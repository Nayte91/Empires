<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Advisory\ScoreStandingRule;
use App\Game\AdvisoryLevel;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ScoreStandingRuleTest extends WebTestCase
{
    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (initialized in setUp)

    private ScoreStandingRule $rule; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->rule = self::getContainer()->get(ScoreStandingRule::class);
    }

    #[Test]
    public function theLeaderReadsTheirMarginOverTheRunnerUp(): void
    {
        $game = $this->createGame();
        $leader = $this->createPlayer($game, 'Alice', 9);
        $this->createPlayer($game, 'Bob', 4);

        $advisory = $this->rule->evaluate($leader);

        $this->assertSame('You lead by 5 VP', $advisory->message);
        $this->assertSame(AdvisoryLevel::Good, $advisory->level);
    }

    #[Test]
    public function aChaserReadsTheirPlaceAndDeficit(): void
    {
        $game = $this->createGame();
        $this->createPlayer($game, 'Alice', 9);
        $this->createPlayer($game, 'Bob', 6);
        $third = $this->createPlayer($game, 'Carol', 1);

        $advisory = $this->rule->evaluate($third);

        $this->assertSame('You are 3rd, 8 VP behind the leader', $advisory->message);
        $this->assertSame(AdvisoryLevel::Neutral, $advisory->level);
    }

    #[Test]
    public function tiedLeadersShareTheLead(): void
    {
        $game = $this->createGame();
        $first = $this->createPlayer($game, 'Alice', 6);
        $this->createPlayer($game, 'Bob', 6);

        $this->assertSame('You share the lead', $this->rule->evaluate($first)->message);
    }

    /** Nobody to compare against reads as leading, never as sharing. */
    #[Test]
    public function aSoloPlayerSimplyLeads(): void
    {
        $game = $this->createGame();
        $alone = $this->createPlayer($game, 'Alice', 5);

        $this->assertSame('You lead the game', $this->rule->evaluate($alone)->message);
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
