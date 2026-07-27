<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game;

use App\Game\AstCatalog;
use App\Game\ASTVersion;
use App\Game\Dto\AstEraDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstCatalogTest extends TestCase
{
    private AstCatalog $astCatalog;

    protected function setUp(): void
    {
        $this->astCatalog = new AstCatalog(\dirname(__DIR__, 3).'/config/game/ast.yaml');
    }

    #[Test]
    public function getErasReturnsTheSevenErasInFileOrder(): void
    {
        $eras = $this->astCatalog->getEras();

        $this->assertCount(7, $eras);
        $this->assertSame(['start', 'stone_age', 'early_bronze_age', 'middle_bronze_age', 'late_bronze_age', 'early_iron_age', 'late_iron_age'], array_map(static fn (AstEraDefinition $era): string => $era->key, $eras));
    }

    #[Test]
    public function stoneAgeHasEmptyRequirementsForBothModes(): void
    {
        $stoneAge = $this->astCatalog->getEras()[1];

        $this->assertSame([], $stoneAge->basicRequirements);
        $this->assertSame([], $stoneAge->expertRequirements);
    }

    #[Test]
    public function lateIronAgeHasItsFullBasicAndExpertRequirements(): void
    {
        $lateIronAge = $this->astCatalog->getEraForPosition(15, ASTVersion::BASIC, 'standard');

        $this->assertSame('late_iron_age', $lateIronAge->key);
        $this->assertSame(['cities' => 5, 'advances' => 3, 'min_advance_cost' => 200], $lateIronAge->basicRequirements);
        $this->assertSame(['cities' => 6, 'advances' => 17, 'max_advance_cost' => 99, 'advance_points' => 56], $lateIronAge->expertRequirements);
    }

    #[Test]
    #[DataProvider('provideGetEraForPositionResolvesTheStandardBasicTrackCases')]
    public function getEraForPositionResolvesTheStandardBasicTrack(int $position, string $expectedEraKey): void
    {
        $this->assertSame($expectedEraKey, $this->astCatalog->getEraForPosition($position, ASTVersion::BASIC, 'standard')->key);
    }

    /** @return iterable<string, array{int, string}> */
    public static function provideGetEraForPositionResolvesTheStandardBasicTrackCases(): iterable
    {
        yield 'position 0 is start' => [0, 'start'];

        yield 'position 4 is stone age' => [4, 'stone_age'];

        yield 'position 5 is early bronze age' => [5, 'early_bronze_age'];

        yield 'position 15 is late iron age' => [15, 'late_iron_age'];

        yield 'negative position clamps to start' => [-3, 'start'];

        yield 'out of bounds position clamps to late iron age' => [99, 'late_iron_age'];
    }

    #[Test]
    public function getEraForPositionResolvesDifferentlyPerEmpireGroup(): void
    {
        $this->assertSame('middle_bronze_age', $this->astCatalog->getEraForPosition(8, ASTVersion::BASIC, 'standard')->key);
        $this->assertSame('early_bronze_age', $this->astCatalog->getEraForPosition(8, ASTVersion::BASIC, 'slow_starter')->key);
    }

    #[Test]
    #[DataProvider('provideGetTrackLengthReturnsTheCorrectTotalForEachTrackCases')]
    public function getTrackLengthReturnsTheCorrectTotalForEachTrack(ASTVersion $astVersion, string $group, int $expectedLength): void
    {
        $this->assertSame($expectedLength, $this->astCatalog->getTrackLength($astVersion, $group));
    }

    /** @return iterable<string, array{ASTVersion, string, int}> */
    public static function provideGetTrackLengthReturnsTheCorrectTotalForEachTrackCases(): iterable
    {
        yield 'basic standard' => [ASTVersion::BASIC, 'standard', 16];

        yield 'basic slow starter' => [ASTVersion::BASIC, 'slow_starter', 16];

        yield 'basic extended early bronze' => [ASTVersion::BASIC, 'extended_early_bronze', 16];

        yield 'expert standard' => [ASTVersion::EXPERT, 'standard', 17];

        yield 'expert extended stone age' => [ASTVersion::EXPERT, 'extended_stone_age', 17];
    }

    #[Test]
    #[DataProvider('provideResolveEmpireGroupReturnsTheGroupForTheVersionCases')]
    public function resolveEmpireGroupReturnsTheGroupForTheVersion(ASTVersion $astVersion, ?string $empireSlug, string $expectedGroup): void
    {
        $this->assertSame($expectedGroup, $this->astCatalog->resolveEmpireGroup($astVersion, $empireSlug));
    }

    /** @return iterable<string, array{ASTVersion, ?string, string}> */
    public static function provideResolveEmpireGroupReturnsTheGroupForTheVersionCases(): iterable
    {
        yield 'minoa is a slow starter in basic' => [ASTVersion::BASIC, 'minoa', 'slow_starter'];

        yield 'celt has an extended early bronze age in basic' => [ASTVersion::BASIC, 'celt', 'extended_early_bronze'];

        yield 'kushan has an extended stone age in expert' => [ASTVersion::EXPERT, 'kushan', 'extended_stone_age'];

        yield 'kushan is standard in basic' => [ASTVersion::BASIC, 'kushan', 'standard'];

        yield 'null empire defaults to standard' => [ASTVersion::BASIC, null, 'standard'];

        yield 'unknown empire falls back to standard' => [ASTVersion::BASIC, 'not-a-real-empire', 'standard'];
    }
}
