<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\DTO\AstEraDefinition;
use App\Repository\AstRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstRepositoryTest extends TestCase
{
    private AstRepository $astRepository;

    protected function setUp(): void
    {
        $this->astRepository = new AstRepository(\dirname(__DIR__, 2).'/config/game/ast.yaml');
    }

    #[Test]
    public function getTrackLengthReturnsSixteen(): void
    {
        self::assertSame(16, $this->astRepository->getTrackLength());
    }

    #[Test]
    public function getErasReturnsTheSevenErasInFileOrder(): void
    {
        $eras = $this->astRepository->getEras();

        self::assertCount(7, $eras);
        self::assertSame(['start', 'stone_age', 'early_bronze_age', 'middle_bronze_age', 'late_bronze_age', 'early_iron_age', 'late_iron_age'], array_map(static fn (AstEraDefinition $era): string => $era->key, $eras));
    }

    #[Test]
    public function stoneAgeHasEmptyRequirementsForBothModes(): void
    {
        $stoneAge = $this->astRepository->getEras()[1];

        self::assertSame([], $stoneAge->basicRequirements);
        self::assertSame([], $stoneAge->expertRequirements);
    }

    #[Test]
    public function lateIronAgeHasItsFullBasicAndExpertRequirements(): void
    {
        $lateIronAge = $this->astRepository->getEraForPosition(15);

        self::assertSame('late_iron_age', $lateIronAge->key);
        self::assertSame(['cities' => 5, 'advances' => 3, 'min_advance_cost' => 200], $lateIronAge->basicRequirements);
        self::assertSame(['cities' => 6, 'advances' => 17, 'max_advance_cost' => 99, 'advance_points' => 56], $lateIronAge->expertRequirements);
    }

    #[Test]
    public function getEraForPositionZeroReturnsStart(): void
    {
        self::assertSame('start', $this->astRepository->getEraForPosition(0)->key);
    }

    #[Test]
    public function getEraForPositionFourReturnsStoneAge(): void
    {
        self::assertSame('stone_age', $this->astRepository->getEraForPosition(4)->key);
    }

    #[Test]
    public function getEraForPositionFiveReturnsEarlyBronzeAge(): void
    {
        self::assertSame('early_bronze_age', $this->astRepository->getEraForPosition(5)->key);
    }

    #[Test]
    public function getEraForPositionFifteenReturnsLateIronAge(): void
    {
        self::assertSame('late_iron_age', $this->astRepository->getEraForPosition(15)->key);
    }

    #[Test]
    public function getEraForPositionClampsNegativePositionToStart(): void
    {
        self::assertSame('start', $this->astRepository->getEraForPosition(-3)->key);
    }

    #[Test]
    public function getEraForPositionClampsOutOfBoundsPositionToLateIronAge(): void
    {
        self::assertSame('late_iron_age', $this->astRepository->getEraForPosition(99)->key);
    }
}
