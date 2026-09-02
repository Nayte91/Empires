<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Advisory;

use App\Presentation\Advisory\CitySupportRule;
use App\Rules\CitySupportCalculator;
use App\Presentation\Advisory\Advisory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\Fixture\PlayerBuilder;

final class CitySupportRuleTest extends TestCase
{
    #[Test]
    #[DataProvider('provideSufficientCensusYieldsNoAdvisoryCases')]
    public function sufficientCensusYieldsNoAdvisory(int $cities, int $census): void
    {
        $player = PlayerBuilder::named('Bob')->withCities($cities)->withCensus($census)->build();

        $this->assertNotInstanceOf(\App\Presentation\Advisory\Advisory::class, new CitySupportRule(new CitySupportCalculator())->evaluate($player));
    }

    /** @return iterable<string, array{int, int}> */
    public static function provideSufficientCensusYieldsNoAdvisoryCases(): iterable
    {
        yield 'census exactly covers cities' => [3, 6];

        yield 'fresh player with default stats' => [0, 1];
    }

    #[Test]
    public function insufficientCensusGetsCantSupportCitiesAdvisory(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(3)->withCensus(5)->build();

        $advisory = new CitySupportRule(new CitySupportCalculator())->evaluate($player);

        $this->assertInstanceOf(Advisory::class, $advisory);
        $this->assertSame("You can't support your cities!", $advisory->message);
    }
}
