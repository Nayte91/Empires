<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Advisory;

use App\Presentation\Advisory\MovementOrderRule;
use App\Presentation\Advisory\AdvisoryLevel;
use App\Rules\Ruleset\EmpireRegistry;
use App\Rules\Ruleset\ScenarioRegistry;
use App\Rules\CensusOrderCalculator;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;

final class MovementOrderRuleTest extends TestCase
{
    #[Test]
    public function theRankIsSpelledAsAnOrdinalExceptForTheLastPlayer(): void
    {
        $game = GameBuilder::create()->build();
        $first = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withCensus(30)->build();
        $second = PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->withCensus(20)->build();
        $third = PlayerBuilder::named('Carol')->in($game)->withEmpire('assyria')->withCensus(10)->build();
        $fourth = PlayerBuilder::named('Dave')->in($game)->withEmpire('egypt')->withCensus(5)->build();

        $rule = $this->rule();

        $this->assertSame('You play 1st this turn', $rule->evaluate($first)->message);
        $this->assertSame('You play 2nd this turn', $rule->evaluate($second)->message);
        $this->assertSame('You play 3rd this turn', $rule->evaluate($third)->message);
        $this->assertSame('You play last this turn', $rule->evaluate($fourth)->message);
    }

    #[Test]
    public function movingFirstIsCautionedAndMovingLastIsGoodNews(): void
    {
        $game = GameBuilder::create()->build();
        $first = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withCensus(30)->build();
        $middle = PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->withCensus(20)->build();
        $last = PlayerBuilder::named('Carol')->in($game)->withEmpire('assyria')->withCensus(10)->build();

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
