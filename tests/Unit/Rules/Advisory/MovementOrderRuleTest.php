<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules\Advisory;

use App\State\Game;
use App\State\Player;
use App\Rules\Advisory\MovementOrderRule;
use App\Rules\Advisory\AdvisoryLevel;
use App\Rules\Ruleset\EmpireRegistry;
use App\Rules\Ruleset\ScenarioRegistry;
use App\Rules\CensusOrderCalculator;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MovementOrderRuleTest extends TestCase
{
    #[Test]
    public function theRankIsSpelledAsAnOrdinalExceptForTheLastPlayer(): void
    {
        $game = new Game();
        $first = new Player($game, 'Alice', 'minoa');
        $first->census = 30;
        $second = new Player($game, 'Bob', 'saba');
        $second->census = 20;
        $third = new Player($game, 'Carol', 'assyria');
        $third->census = 10;
        $fourth = new Player($game, 'Dave', 'egypt');
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
        $game = new Game();
        $first = new Player($game, 'Alice', 'minoa');
        $first->census = 30;
        $middle = new Player($game, 'Bob', 'saba');
        $middle->census = 20;
        $last = new Player($game, 'Carol', 'assyria');
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
                new EmpireRegistry(
                    $projectDir.'/config/game/empires.yaml',
                    new ScenarioRegistry($projectDir.'/config/game/scenarios.yaml'),
                ),
                GameConfig::advanceEffects(),
            ),
        );
    }
}
