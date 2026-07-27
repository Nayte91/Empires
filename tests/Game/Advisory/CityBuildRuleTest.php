<?php

declare(strict_types=1);

namespace App\Tests\Game\Advisory;

use App\Game\Advisory\CityBuildRule;
use App\Game\AdvisoryLevel;
use App\Game\GameData;
use App\Game\Service\CityBuildCalculator;
use App\Game\Service\CitySupportCalculator;
use App\Tests\Support\Fixture\PlayerBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CityBuildRuleTest extends TestCase
{
    #[Test]
    public function aSingleCityIsWordedWithoutTheUpToQualifier(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 3;
        $player->census = 20;

        $advisory = $this->rule()->evaluate($player);

        $this->assertSame('You can build 1 city', $advisory->message);
        $this->assertSame(AdvisoryLevel::Neutral, $advisory->level);
    }

    #[Test]
    public function severalCitiesAreStatedAsACeiling(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 2;
        $player->census = 30;

        $this->assertSame('You can build up to 3 cities', $this->rule()->evaluate($player)->message);
    }

    #[Test]
    public function anEmpireTooPoorToBuildIsToldSoPlainly(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 5;
        $player->census = 12;

        $advisory = $this->rule()->evaluate($player);

        $this->assertSame('You cannot build any city', $advisory->message);
        $this->assertSame(AdvisoryLevel::Neutral, $advisory->level);
    }

    /** The rebate is counted before the verdict: the same empire can build once it can pay. */
    #[Test]
    public function theArchitectureRebateCanTurnTheVerdictAround(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 5;
        $player->census = 17;
        $player->treasury = 3;

        $this->assertSame('You cannot build any city', $this->rule()->evaluate($player)->message);

        $player->ownAdvances([CityBuildCalculator::ARCHITECTURE_ADVANCE]);

        $this->assertSame('You can build 1 city', $this->rule()->evaluate($player)->message);
    }

    /** A full empire is not a shortage: it is the top of the track, and reads as good news. */
    #[Test]
    public function afullEmpireIsGoodNewsRatherThanAShortage(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 9;
        $player->census = 55;

        $advisory = $this->rule()->evaluate($player);

        $this->assertSame('Your empire has all the cities it may hold', $advisory->message);
        $this->assertSame(AdvisoryLevel::Good, $advisory->level);
    }

    private function rule(): CityBuildRule
    {
        return new CityBuildRule(new CityBuildCalculator(
            new CitySupportCalculator(),
            new GameData(\dirname(__DIR__, 3).'/config/game/game_data.yaml'),
        ));
    }
}
