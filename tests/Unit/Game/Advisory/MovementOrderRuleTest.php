<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Advisory;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Advisory\MovementOrderRule;
use App\Game\AdvisoryLevel;
use App\Game\EmpireCatalog;
use App\Game\ScenarioCatalog;
use App\Game\Service\CensusOrderCalculator;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MovementOrderRuleTest extends TestCase
{
    #[Test]
    public function theRankIsSpelledAsAnOrdinalExceptForTheLastPlayer(): void
    {
        $game = new GameSession();
        $first = new Player($game, 'Alice');
        $first->census = 30;
        $second = new Player($game, 'Bob');
        $second->census = 20;
        $third = new Player($game, 'Carol');
        $third->census = 10;
        $fourth = new Player($game, 'Dave');
        $fourth->census = 5;

        $rule = $this->rule();

        $this->assertSame('You play 1st this turn', $rule->evaluate($first)->message);
        $this->assertSame('You play 2nd this turn', $rule->evaluate($second)->message);
        $this->assertSame('You play 3rd this turn', $rule->evaluate($third)->message);
        $this->assertSame('You play last this turn', $rule->evaluate($fourth)->message);
    }

    /**
     * Moving late is an advantage in Mega Empires, so the rank is graded: opening the turn is a
     * position to watch, closing it is good news, and everything between is unremarkable.
     */
    #[Test]
    public function movingFirstIsCautionedAndMovingLastIsGoodNews(): void
    {
        $game = new GameSession();
        $first = new Player($game, 'Alice');
        $first->census = 30;
        $middle = new Player($game, 'Bob');
        $middle->census = 20;
        $last = new Player($game, 'Carol');
        $last->census = 10;

        $rule = $this->rule();

        $this->assertSame(AdvisoryLevel::Caution, $rule->evaluate($first)->level);
        $this->assertSame(AdvisoryLevel::Neutral, $rule->evaluate($middle)->level);
        $this->assertSame(AdvisoryLevel::Good, $rule->evaluate($last)->level);
    }

    private function rule(): MovementOrderRule
    {
        $projectDir = \dirname(__DIR__, 4);

        return new MovementOrderRule(
            new CensusOrderCalculator(
                new EmpireCatalog(
                    $projectDir.'/config/game/empires.yaml',
                    new ScenarioCatalog($projectDir.'/config/game/scenarios.yaml'),
                ),
                GameConfig::advanceEffects(),
            ),
        );
    }
}
