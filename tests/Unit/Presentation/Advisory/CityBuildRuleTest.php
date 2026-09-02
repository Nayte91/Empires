<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Advisory;

use App\Presentation\Advisory\CityBuildRule;
use App\Presentation\Advisory\AdvisoryLevel;
use App\Rules\CityBuildCalculator;
use App\Rules\CitySupportCalculator;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CityBuildRuleTest extends TestCase
{
    #[Test]
    public function aSingleCityIsWordedWithoutTheUpToQualifier(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(3)->withCensus(20)->build();

        $advisory = $this->rule()->evaluate($player);

        $this->assertSame('You can build 1 city', $advisory->message);
        $this->assertSame(AdvisoryLevel::Neutral, $advisory->level);
    }

    #[Test]
    public function severalCitiesAreStatedAsACeiling(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(2)->withCensus(30)->build();

        $this->assertSame('You can build up to 3 cities', $this->rule()->evaluate($player)->message);
    }

    #[Test]
    public function anEmpireTooPoorToBuildIsToldSoPlainly(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(5)->withCensus(12)->build();

        $advisory = $this->rule()->evaluate($player);

        $this->assertSame('You cannot build any city', $advisory->message);
        $this->assertSame(AdvisoryLevel::Neutral, $advisory->level);
    }

    #[Test]
    public function theArchitectureRebateCanTurnTheVerdictAround(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(5)->withCensus(17)->withTreasury(3)->build();

        $this->assertSame('You cannot build any city', $this->rule()->evaluate($player)->message);

        $player->ownAdvances(['architecture']);

        $this->assertSame('You can build 1 city', $this->rule()->evaluate($player)->message);
    }

    #[Test]
    public function afullEmpireIsGoodNewsRatherThanAShortage(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(9)->withCensus(55)->build();

        $advisory = $this->rule()->evaluate($player);

        $this->assertSame('Your empire has all the cities it may hold', $advisory->message);
        $this->assertSame(AdvisoryLevel::Good, $advisory->level);
    }

    private function rule(): CityBuildRule
    {
        return new CityBuildRule(new CityBuildCalculator(
            new CitySupportCalculator(),
            GameConfig::gameRegistry(),
            GameConfig::advanceEffects(),
        ));
    }
}
