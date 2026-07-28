<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules;

use App\Rules\Action\Stat;
use App\Rules\StatBoundsCalculator;
use App\Rules\StockCalculator;
use App\State\ASTVersion;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StatBoundsCalculatorTest extends TestCase
{
    #[Test]
    #[DataProvider('provideFloorForEachStatCases')]
    public function floorForEachStat(Stat $stat, int $expectedFloor): void
    {
        $player = PlayerBuilder::named('Bob')->build();

        $this->assertSame($expectedFloor, $this->calculator()->floorFor($player, $stat));
    }

    /** @return iterable<string, array{Stat, int}> */
    public static function provideFloorForEachStatCases(): iterable
    {
        yield 'cities floor at zero' => [Stat::Cities, 0];

        yield 'census floors at one, the only stat that does not start at zero' => [Stat::Census, 1];

        yield 'treasury floors at zero' => [Stat::Treasury, 0];

        yield 'ships floor at zero' => [Stat::Ships, 0];

        yield 'cards floor at zero' => [Stat::Cards, 0];

        yield 'ast position floors at zero, the start of the track' => [Stat::AstPosition, 0];
    }

    #[Test]
    #[DataProvider('provideCeilingForEachStatWithNoLiveDependencyCases')]
    public function ceilingForEachStatWithNoLiveDependency(Stat $stat, int $expectedCeiling): void
    {
        $player = PlayerBuilder::named('Bob')->build();

        $this->assertSame($expectedCeiling, $this->calculator()->ceilingFor($player, $stat));
    }

    /** @return iterable<string, array{Stat, int}> */
    public static function provideCeilingForEachStatWithNoLiveDependencyCases(): iterable
    {
        yield 'cities ceiling comes from GameRegistry max_cities' => [Stat::Cities, 9];

        yield 'ships ceiling comes from GameRegistry max_ships' => [Stat::Ships, 4];

        yield 'cards ceiling is a permissive display bound, not the hand-size limit' => [Stat::Cards, 20];
    }

    /** Census and treasury share one pool, so each one's ceiling answers to what its twin holds. */
    #[Test]
    public function censusAndTreasuryCeilingsAreBoundedByTheirTwin(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->census = 20;
        $player->treasury = 15;

        $calculator = $this->calculator();

        $this->assertSame(40, $calculator->ceilingFor($player, Stat::Census));
        $this->assertSame(35, $calculator->ceilingFor($player, Stat::Treasury));
    }

    /**
     * The AST ceiling is exact per version and empire group — every group of a given version
     * shares the same track length, but a null empire must still fall back to the standard group.
     */
    #[Test]
    #[DataProvider('provideAstPositionCeilingPerVersionAndGroupCases')]
    public function astPositionCeilingPerVersionAndGroup(ASTVersion $version, ?string $empire, int $expectedCeiling): void
    {
        $game = GameBuilder::create()->build();
        $game->astVersion = $version;
        $builder = PlayerBuilder::named('Bob')->in($game);

        if (null !== $empire) {
            $builder = $builder->withEmpire($empire);
        }

        $player = $builder->build();

        $this->assertSame($expectedCeiling, $this->calculator()->ceilingFor($player, Stat::AstPosition));
    }

    /** @return iterable<string, array{ASTVersion, ?string, int}> */
    public static function provideAstPositionCeilingPerVersionAndGroupCases(): iterable
    {
        yield 'basic standard group, track length 16' => [ASTVersion::BASIC, 'assyria', 15];

        yield 'basic slow starter group, also track length 16' => [ASTVersion::BASIC, 'minoa', 15];

        yield 'basic extended early bronze group, also track length 16' => [ASTVersion::BASIC, 'celt', 15];

        yield 'basic null empire falls back to the standard group' => [ASTVersion::BASIC, null, 15];

        yield 'expert standard group, track length 17' => [ASTVersion::EXPERT, 'assyria', 16];

        yield 'expert extended stone age group, also track length 17' => [ASTVersion::EXPERT, 'minoa', 16];
    }

    private function calculator(): StatBoundsCalculator
    {
        return new StatBoundsCalculator(
            GameConfig::gameRegistry(),
            new StockCalculator(GameConfig::gameRegistry()),
            GameConfig::astRegistry(),
        );
    }
}
