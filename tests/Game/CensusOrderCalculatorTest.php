<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\EmpireCatalog;
use App\Game\ScenarioCatalog;
use App\Game\Service\CensusOrderCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CensusOrderCalculatorTest extends TestCase
{
    #[Test]
    public function higherCensusPlaysFirst(): void
    {
        $game = new GameSession();
        $low = new Player($game, 'Bob');
        $low->census = 10;
        $high = new Player($game, 'Alice');
        $high->census = 30;

        $order = $this->calculator()->orderFor($game);

        $this->assertSame([$high, $low], $order);
    }

    #[Test]
    public function tiedCensusIsBrokenByEmpirePosition(): void
    {
        $game = new GameSession();
        $assyria = new Player($game, 'Bob');
        $assyria->census = 20;
        $assyria->empire = 'assyria';
        $minoa = new Player($game, 'Alice');
        $minoa->census = 20;
        $minoa->empire = 'minoa';

        $order = $this->calculator()->orderFor($game);

        $this->assertSame([$minoa, $assyria], $order);
    }

    #[Test]
    public function militaryPlayerMovesAfterHigherCensusNonMilitary(): void
    {
        $game = new GameSession();
        $military = new Player($game, 'Bob');
        $military->census = 40;
        $military->ownAdvances(['military']);
        $nonMilitary = new Player($game, 'Alice');
        $nonMilitary->census = 5;

        $order = $this->calculator()->orderFor($game);

        $this->assertSame([$nonMilitary, $military], $order);
    }

    #[Test]
    public function twoMilitaryPlayersAreOrderedByCensusThenPosition(): void
    {
        $game = new GameSession();
        $first = new Player($game, 'Bob');
        $first->census = 20;
        $first->empire = 'assyria';
        $first->ownAdvances(['military']);
        $second = new Player($game, 'Alice');
        $second->census = 20;
        $second->empire = 'minoa';
        $second->ownAdvances(['military']);

        $order = $this->calculator()->orderFor($game);

        $this->assertSame([$second, $first], $order);
    }

    #[Test]
    public function nullOrUnknownEmpirePlaysLastAtEqualCensus(): void
    {
        $game = new GameSession();
        $known = new Player($game, 'Bob');
        $known->census = 15;
        $known->empire = 'minoa';
        $unknown = new Player($game, 'Alice');
        $unknown->census = 15;
        $unknown->empire = 'atlantis';
        $noEmpire = new Player($game, 'Kangoo');
        $noEmpire->census = 15;

        $order = $this->calculator()->orderFor($game);

        $this->assertSame([$known, $unknown, $noEmpire], $order);
    }

    private function calculator(): CensusOrderCalculator
    {
        $projectDir = dirname(__DIR__, 2);

        return new CensusOrderCalculator(
            new EmpireCatalog(
                $projectDir.'/config/game/empires.yaml',
                new ScenarioCatalog($projectDir.'/config/game/scenarios.yaml'),
            ),
        );
    }
}
