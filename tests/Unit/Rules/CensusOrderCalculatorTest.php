<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules;

use App\Rules\CensusOrderCalculator;
use App\Rules\Ruleset\EmpireRegistry;
use App\Rules\Ruleset\ScenarioRegistry;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CensusOrderCalculatorTest extends TestCase
{
    #[Test]
    public function higherCensusPlaysFirst(): void
    {
        $game = GameBuilder::create()->build();
        $low = PlayerBuilder::named('Bob')->in($game)->withEmpire('assyria')->withCensus(10)->build();
        $high = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withCensus(30)->build();

        $order = $this->calculator()->orderFor($game);

        $this->assertSame([$high, $low], $order);
    }

    #[Test]
    public function tiedCensusIsBrokenByEmpirePosition(): void
    {
        $game = GameBuilder::create()->build();
        $assyria = PlayerBuilder::named('Bob')->in($game)->withEmpire('assyria')->withCensus(20)->build();
        $minoa = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withCensus(20)->build();

        $order = $this->calculator()->orderFor($game);

        $this->assertSame([$minoa, $assyria], $order);
    }

    #[Test]
    public function militaryPlayerMovesAfterHigherCensusNonMilitary(): void
    {
        $game = GameBuilder::create()->build();
        $military = PlayerBuilder::named('Bob')->in($game)->withEmpire('assyria')->withCensus(40)->withAdvances(['military'])->build();
        $nonMilitary = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withCensus(5)->build();

        $order = $this->calculator()->orderFor($game);

        $this->assertSame([$nonMilitary, $military], $order);
    }

    #[Test]
    public function twoMilitaryPlayersAreOrderedByCensusThenPosition(): void
    {
        $game = GameBuilder::create()->build();
        $first = PlayerBuilder::named('Bob')->in($game)->withEmpire('assyria')->withCensus(20)->withAdvances(['military'])->build();
        $second = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withCensus(20)->withAdvances(['military'])->build();

        $order = $this->calculator()->orderFor($game);

        $this->assertSame([$second, $first], $order);
    }

    #[Test]
    public function anUnknownEmpirePlaysLastAtEqualCensus(): void
    {
        $game = GameBuilder::create()->build();
        $known = PlayerBuilder::named('Bob')->in($game)->withEmpire('minoa')->withCensus(15)->build();
        $unknown = PlayerBuilder::named('Alice')->in($game)->withEmpire('atlantis')->withCensus(15)->build();

        $order = $this->calculator()->orderFor($game);

        $this->assertSame([$known, $unknown], $order);
    }

    #[Test]
    public function rankOfCountsFromOneDownTheOrder(): void
    {
        $game = GameBuilder::create()->build();
        $first = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withCensus(30)->build();
        $second = PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->withCensus(20)->build();
        $third = PlayerBuilder::named('Carol')->in($game)->withEmpire('assyria')->withCensus(10)->build();

        $calculator = $this->calculator();

        $this->assertSame(1, $calculator->rankOf($first));
        $this->assertSame(2, $calculator->rankOf($second));
        $this->assertSame(3, $calculator->rankOf($third));
    }

    #[Test]
    public function aMilitaryOwnerRanksLastDespiteTheHighestCensus(): void
    {
        $game = GameBuilder::create()->build();
        $general = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withCensus(40)->withAdvances(['military'])->build();
        $civilian = PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->withCensus(10)->build();

        $calculator = $this->calculator();

        $this->assertSame(2, $calculator->rankOf($general));
        $this->assertSame(1, $calculator->rankOf($civilian));
    }

    private function calculator(): CensusOrderCalculator
    {
        $projectDir = dirname(__DIR__, 3);

        return new CensusOrderCalculator(
            new EmpireRegistry(
                $projectDir.'/config/game/empires.yaml',
                new ScenarioRegistry($projectDir.'/config/game/scenarios.yaml'),
            ),
            GameConfig::advanceEffects(),
        );
    }
}
