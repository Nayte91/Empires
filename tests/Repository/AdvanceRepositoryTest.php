<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Enumeration\Category;
use App\Repository\AdvanceRepository;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdvanceRepositoryTest extends WebTestCase
{
    private AdvanceRepository $advanceRepository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->advanceRepository = self::getContainer()->get(AdvanceRepository::class);
    }

    #[Test]
    public function getAdvancesLoadsAllFiftyOneAdvances(): void
    {
        $advances = $this->advanceRepository->getAdvances();

        self::assertCount(51, $advances);
    }

    #[Test]
    public function anatomyHasItsKeyCostAndMitigation(): void
    {
        $advance = $this->advanceRepository->getAdvanceByName('anatomy');

        self::assertSame('anatomy', $advance->key);
        self::assertSame(['epidemic'], $advance->mitigations);
        self::assertSame(270, $advance->cost);
    }

    #[Test]
    public function agricultureHasItsAggravationAndNamedCredit(): void
    {
        $advance = $this->advanceRepository->getAdvanceByName('agriculture');

        self::assertSame(['famine'], $advance->aggravations);
        self::assertArrayHasKey('democracy', $advance->credits);
        self::assertSame(20, $advance->credits['democracy']);
    }

    #[Test]
    public function monarchyHasBothMitigationAndAggravation(): void
    {
        $advance = $this->advanceRepository->getAdvanceByName('monarchy');

        self::assertSame(['barbarian_hordes'], $advance->mitigations);
        self::assertSame(['tyranny'], $advance->aggravations);
    }

    #[Test]
    public function architectureHasNoMitigationNorAggravation(): void
    {
        $advance = $this->advanceRepository->getAdvanceByName('architecture');

        self::assertSame([], $advance->mitigations);
        self::assertSame([], $advance->aggravations);
    }

    #[Test]
    public function getCategoryColorsReturnsExactlyTheFiveEnumCategoriesWithValidHexColors(): void
    {
        $colors = $this->advanceRepository->getCategoryColors();

        $expectedKeys = array_map(static fn (Category $category): string => $category->value, Category::cases());
        self::assertSame($expectedKeys, array_keys($colors));

        foreach ($colors as $hex) {
            self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $hex);
        }
    }

    #[Test]
    public function getCategoryColorsReturnsTheOfficialHexValues(): void
    {
        $colors = $this->advanceRepository->getCategoryColors();

        self::assertSame('#27AAE1', $colors['art']);
        self::assertSame('#F04E56', $colors['civic']);
        self::assertSame('#F7941E', $colors['craft']);
        self::assertSame('#FFF200', $colors['religion']);
        self::assertSame('#39B54A', $colors['science']);
    }
}
