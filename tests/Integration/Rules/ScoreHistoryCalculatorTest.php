<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules;

use App\Rules\ScoreHistoryCalculator;
use App\State\Game;
use App\State\Order;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;

/**
 * The series behind the chronicle's chart. It is the one place the game reads a *past* turn, and it
 * only holds because validated baskets are the sole record left of what an empire owned when — hence
 * a database test against the real advance catalog rather than a fabricated one.
 *
 * A score here is advance points plus the marker's position, so every test that pins the *advance*
 * half parks the player on A.S.T. position 0: the ceiling in AstProgressionCalculator then holds the
 * whole replayed run at zero, and the numbers are advance points and nothing else. The one test that
 * pins the other half moves the marker instead. How the marker is replayed is
 * AstProgressionCalculatorTest's subject, not this class's.
 *
 * Fixtures hold no cities, which is what lets the last point — the player's real score, not a
 * reconstruction — carry the same number as the one before it.
 */
final class ScoreHistoryCalculatorTest extends WebTestCase
{
    use GameFixtureTrait;

    private ScoreHistoryCalculator $scoreHistoryCalculator; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        $this->initEntityManager();

        $this->scoreHistoryCalculator = self::getContainer()->get(ScoreHistoryCalculator::class);
    }

    /** Points are what an empire *owns*, not what it bought that turn: a turn with no basket holds the line. */
    #[Test]
    public function aSeriesCumulatesItsBasketsAndStaysFlatBetweenThem(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(4)->persist($this->entityManager);
        $alice = $this->createPlayerAtTheStartOfTheTrack('Alice', $game);
        $this->createOrder($alice, 1, ['pottery'], validated: true);
        $this->createOrder($alice, 3, ['agriculture'], validated: true);

        $this->assertSame([1, 1, 4, 4], $this->scoreHistoryCalculator->pointsPerTurn($game)['alice']);
    }

    /** A basket the operator never validated was never delivered, so it never happened. */
    #[Test]
    public function aPendingBasketNeverEntersTheSeries(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(3)->persist($this->entityManager);
        $alice = $this->createPlayerAtTheStartOfTheTrack('Alice', $game);
        $this->createOrder($alice, 1, ['pottery'], validated: true);
        $this->createOrder($alice, 2, ['democracy'], validated: false);

        $this->assertSame([1, 1, 1], $this->scoreHistoryCalculator->pointsPerTurn($game)['alice']);
    }

    /** A flat line is an answer: an empire that bought nothing must still be drawable. */
    #[Test]
    public function aPlayerWhoNeverBoughtAnythingStillGetsAFlatSeries(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(3)->persist($this->entityManager);
        $this->createPlayerAtTheStartOfTheTrack('Alice', $game);

        $this->assertSame([0, 0, 0], $this->scoreHistoryCalculator->pointsPerTurn($game)['alice']);
    }

    /** The chart's x-axis is the game that was played, not the twenty turns the rules allow. */
    #[Test]
    public function theSeriesStopsOnTheTurnTheGameStoppedOn(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(5)->persist($this->entityManager);
        $alice = $this->createPlayerAtTheStartOfTheTrack('Alice', $game);
        $this->createOrder($alice, 2, ['calendar'], validated: true);

        $this->assertCount($game->currentTurn, $this->scoreHistoryCalculator->pointsPerTurn($game)['alice']);
    }

    #[Test]
    public function eachPlayerGetsTheirOwnSeriesAndNoneOfTheirNeighboursPoints(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(3)->persist($this->entityManager);
        $alice = $this->createPlayerAtTheStartOfTheTrack('Alice', $game);
        $bob = $this->createPlayerAtTheStartOfTheTrack('Bob', $game, 'hellas');
        $this->createOrder($alice, 1, ['pottery'], validated: true);
        $this->createOrder($alice, 3, ['democracy'], validated: true);
        $this->createOrder($bob, 2, ['calendar'], validated: true);

        $this->assertSame(['alice' => [1, 1, 7], 'bob' => [0, 3, 3]], $this->scoreHistoryCalculator->pointsPerTurn($game));
    }

    /**
     * Series are keyed by player slug, which is unique per game and not across games — so a second
     * table running at the same time is exactly what would leak through OrderRepository::
     * findValidatedByGame() if it stopped filtering on the game.
     */
    #[Test]
    public function aBasketBoughtInAnotherGameNeverEntersTheSeries(): void
    {
        $otherGame = GameBuilder::create()->persist($this->entityManager);
        $otherAlice = $this->createPlayerAtTheStartOfTheTrack('Alice', $otherGame);
        $this->createOrder($otherAlice, 1, ['democracy'], validated: true);
        $game = GameBuilder::create()->persist($this->entityManager);
        $this->createPlayerAtTheStartOfTheTrack('Alice', $game);

        $this->assertSame([0], $this->scoreHistoryCalculator->pointsPerTurn($game)['alice']);
    }

    /**
     * The other half of a score, and the reason this class outgrew the advance points it started as.
     * Assyria runs on the standard track, whose first squares are Stone Age ones the marker walks
     * into holding nothing; parked on position 2, it is worth 10 of the 11 points of turn 2.
     */
    #[Test]
    public function theMarkersPositionIsWorthFivePointsEachAndIsAddedToTheAdvancePoints(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(3)->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->withEmpire('assyria')->persist($this->entityManager);
        $alice->astPosition = 2;
        $this->createOrder($alice, 1, ['pottery'], validated: true);

        $this->assertSame([6, 11, 11], $this->scoreHistoryCalculator->pointsPerTurn($game)['alice']);
    }

    /**
     * Position 0 is the entity's own default, but the expectations above only read as advance points
     * while it holds — so the fixture states it rather than inheriting it.
     */
    private function createPlayerAtTheStartOfTheTrack(string $name, Game $game, string $empire = 'minoa'): Player
    {
        $player = PlayerBuilder::named($name)->in($game)->withEmpire($empire)->persist($this->entityManager);
        $player->astPosition = 0;

        return $player;
    }

    /**
     * Validating also hands the advances to the player, as AdvanceFulfillment does in production:
     * the last point of a series is the player's real score, so a fixture that bought without
     * owning would end on a number no game could produce.
     *
     * @param list<string> $slugs
     */
    private function createOrder(Player $player, int $turn, array $slugs, bool $validated): void
    {
        $lines = array_map(static fn (string $slug): OrderLine => new OrderLine($slug, 0), $slugs);

        $order = new Order($player, $turn);
        $order->replaceLines($lines);

        if ($validated) {
            $order->freeze($lines, 0);
            $order->setMarking(OrderStatus::Validated->value);
            $player->ownAdvances($slugs);
        }

        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }
}
