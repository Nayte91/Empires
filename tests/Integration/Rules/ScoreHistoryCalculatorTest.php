<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules;

use App\Rules\ScoreHistoryCalculator;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Tests\Support\Fixture\OrderBuilder;

/**
 * Every test pinning the *advance* half of a score parks the player on A.S.T. position 0 — the
 * ceiling then holds the whole replayed run at zero, so the numbers are advance points and nothing
 * else.
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

    #[Test]
    public function aSeriesCumulatesItsBasketsAndStaysFlatBetweenThem(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(4)->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->withAstPosition(0)->persist($this->entityManager);
        OrderBuilder::for($alice)->onTurn(1)->withKeys('pottery')->validated()->persist($this->entityManager);
        OrderBuilder::for($alice)->onTurn(3)->withKeys('agriculture')->validated()->persist($this->entityManager);

        $this->assertSame([1, 1, 4, 4], $this->scoreHistoryCalculator->pointsPerTurn($game)['alice']);
    }

    #[Test]
    public function aPendingBasketNeverEntersTheSeries(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(3)->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->withAstPosition(0)->persist($this->entityManager);
        OrderBuilder::for($alice)->onTurn(1)->withKeys('pottery')->validated()->persist($this->entityManager);
        OrderBuilder::for($alice)->onTurn(2)->withKeys('democracy')->persist($this->entityManager);

        $this->assertSame([1, 1, 1], $this->scoreHistoryCalculator->pointsPerTurn($game)['alice']);
    }

    #[Test]
    public function aPlayerWhoNeverBoughtAnythingStillGetsAFlatSeries(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(3)->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withAstPosition(0)->persist($this->entityManager);

        $this->assertSame([0, 0, 0], $this->scoreHistoryCalculator->pointsPerTurn($game)['alice']);
    }

    #[Test]
    public function theSeriesStopsOnTheTurnTheGameStoppedOn(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(5)->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->withAstPosition(0)->persist($this->entityManager);
        OrderBuilder::for($alice)->onTurn(2)->withKeys('calendar')->validated()->persist($this->entityManager);

        $this->assertCount($game->currentTurn, $this->scoreHistoryCalculator->pointsPerTurn($game)['alice']);
    }

    #[Test]
    public function eachPlayerGetsTheirOwnSeriesAndNoneOfTheirNeighboursPoints(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(3)->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->withAstPosition(0)->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->withEmpire('hellas')->withAstPosition(0)->persist($this->entityManager);
        OrderBuilder::for($alice)->onTurn(1)->withKeys('pottery')->validated()->persist($this->entityManager);
        OrderBuilder::for($alice)->onTurn(3)->withKeys('democracy')->validated()->persist($this->entityManager);
        OrderBuilder::for($bob)->onTurn(2)->withKeys('calendar')->validated()->persist($this->entityManager);

        $this->assertSame(['alice' => [1, 1, 7], 'bob' => [0, 3, 3]], $this->scoreHistoryCalculator->pointsPerTurn($game));
    }

    /**
     * Slugs are unique per game, not across games: this is what leaks if
     * OrderRepository::findValidatedByGame() stops filtering on the game.
     */
    #[Test]
    public function aBasketBoughtInAnotherGameNeverEntersTheSeries(): void
    {
        $otherGame = GameBuilder::create()->persist($this->entityManager);
        $otherAlice = PlayerBuilder::named('Alice')->in($otherGame)->withAstPosition(0)->persist($this->entityManager);
        OrderBuilder::for($otherAlice)->onTurn(1)->withKeys('democracy')->validated()->persist($this->entityManager);
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withAstPosition(0)->persist($this->entityManager);

        $this->assertSame([0], $this->scoreHistoryCalculator->pointsPerTurn($game)['alice']);
    }

    #[Test]
    public function theMarkersPositionIsWorthFivePointsEachAndIsAddedToTheAdvancePoints(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(3)->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->withEmpire('assyria')->withAstPosition(2)->persist($this->entityManager);
        OrderBuilder::for($alice)->onTurn(1)->withKeys('pottery')->validated()->persist($this->entityManager);

        $this->assertSame([6, 11, 11], $this->scoreHistoryCalculator->pointsPerTurn($game)['alice']);
    }
}
