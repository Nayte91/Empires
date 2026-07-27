<?php

declare(strict_types=1);

namespace App\Tests\Game\Advisory;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Advisory\CitySupportRule;
use App\Game\Service\CitySupportCalculator;
use App\Game\Dto\Advisory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CitySupportRuleTest extends TestCase
{
    #[Test]
    #[DataProvider('provideSufficientCensusYieldsNoAdvisoryCases')]
    public function sufficientCensusYieldsNoAdvisory(int $cities, int $census): void
    {
        $player = new Player(new GameSession(), 'Bob');
        $player->cities = $cities;
        $player->census = $census;

        $this->assertNotInstanceOf(\App\Game\Dto\Advisory::class, new CitySupportRule(new CitySupportCalculator())->evaluate($player));
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
        $player = new Player(new GameSession(), 'Bob');
        $player->cities = 3;
        $player->census = 5;

        $advisory = new CitySupportRule(new CitySupportCalculator())->evaluate($player);

        $this->assertInstanceOf(Advisory::class, $advisory);
        $this->assertSame("You can't support your cities!", $advisory->message);
    }
}
