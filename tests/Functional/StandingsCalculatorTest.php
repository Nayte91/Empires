<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Service\StandingsCalculator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A WebTestCase because resolving what a player owns needs the advance catalogue, which only the
 * container can build.
 */
final class StandingsCalculatorTest extends WebTestCase
{
    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (initialized in setUp)

    private StandingsCalculator $standingsCalculator; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->standingsCalculator = self::getContainer()->get(StandingsCalculator::class);
    }

    #[Test]
    public function theTableIsOrderedByScoreLeaderFirst(): void
    {
        $game = $this->createGame();
        $middle = $this->createPlayer($game, 'Bob', 4);
        $leader = $this->createPlayer($game, 'Alice', 9);
        $last = $this->createPlayer($game, 'Carol', 1);

        $this->assertSame([$leader, $middle, $last], $this->standingsCalculator->standings($game));
        $this->assertSame(1, $this->standingsCalculator->rankOf($leader));
        $this->assertSame(3, $this->standingsCalculator->rankOf($last));
    }

    #[Test]
    public function theGapIsMeasuredAgainstTheLeaderAndIsZeroForThemselves(): void
    {
        $game = $this->createGame();
        $leader = $this->createPlayer($game, 'Alice', 9);
        $chaser = $this->createPlayer($game, 'Bob', 4);

        $this->assertSame(0, $this->standingsCalculator->gapToLeader($leader));
        $this->assertSame(5, $this->standingsCalculator->gapToLeader($chaser));
        $this->assertSame(5, $this->standingsCalculator->leadOverRunnerUp($leader));
    }

    /** Null and zero are different answers: nobody to compare against is not a tie. */
    #[Test]
    public function aSoloPlayerHasNoRunnerUpToMeasureAgainst(): void
    {
        $game = $this->createGame();
        $alone = $this->createPlayer($game, 'Alice', 5);

        $this->assertNull($this->standingsCalculator->leadOverRunnerUp($alone));
    }

    #[Test]
    public function tiedLeadersBothShowAZeroMargin(): void
    {
        $game = $this->createGame();
        $first = $this->createPlayer($game, 'Alice', 6);
        $second = $this->createPlayer($game, 'Bob', 6);

        $this->assertSame(0, $this->standingsCalculator->leadOverRunnerUp($first));
        $this->assertSame(0, $this->standingsCalculator->gapToLeader($second));
    }

    private function createGame(): GameSession
    {
        $game = new GameSession();
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }

    private function createPlayer(GameSession $game, string $name, int $cities): Player
    {
        $player = new Player($game, $name);
        $player->cities = $cities;
        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
    }
}
