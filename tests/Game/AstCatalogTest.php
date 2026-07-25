<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\AstCatalog;
use App\Game\Dto\AstEraDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstCatalogTest extends TestCase
{
    private AstCatalog $astCatalog;

    protected function setUp(): void
    {
        $this->astCatalog = new AstCatalog(\dirname(__DIR__, 2).'/config/game/ast.yaml');
    }

    #[Test]
    public function getTrackLengthReturnsSixteen(): void
    {
        $this->assertSame(16, $this->astCatalog->getTrackLength());
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
        $lateIronAge = $this->astCatalog->getEraForPosition(15);

        $this->assertSame('late_iron_age', $lateIronAge->key);
        $this->assertSame(['cities' => 5, 'advances' => 3, 'min_advance_cost' => 200], $lateIronAge->basicRequirements);
        $this->assertSame(['cities' => 6, 'advances' => 17, 'max_advance_cost' => 99, 'advance_points' => 56], $lateIronAge->expertRequirements);
    }

    #[Test]
    public function getEraForPositionZeroReturnsStart(): void
    {
        $this->assertSame('start', $this->astCatalog->getEraForPosition(0)->key);
    }

    #[Test]
    public function getEraForPositionFourReturnsStoneAge(): void
    {
        $this->assertSame('stone_age', $this->astCatalog->getEraForPosition(4)->key);
    }

    #[Test]
    public function getEraForPositionFiveReturnsEarlyBronzeAge(): void
    {
        $this->assertSame('early_bronze_age', $this->astCatalog->getEraForPosition(5)->key);
    }

    #[Test]
    public function getEraForPositionFifteenReturnsLateIronAge(): void
    {
        $this->assertSame('late_iron_age', $this->astCatalog->getEraForPosition(15)->key);
    }

    #[Test]
    public function getEraForPositionClampsNegativePositionToStart(): void
    {
        $this->assertSame('start', $this->astCatalog->getEraForPosition(-3)->key);
    }

    #[Test]
    public function getEraForPositionClampsOutOfBoundsPositionToLateIronAge(): void
    {
        $this->assertSame('late_iron_age', $this->astCatalog->getEraForPosition(99)->key);
    }
}
