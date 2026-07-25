<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\AdvanceCatalog;
use App\Game\Category;
use App\Game\Dto\Promotion;
use App\Shop\Promotion\ElectiveBenefit;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdvanceCatalogTest extends WebTestCase
{
    private AdvanceCatalog $advanceCatalog;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->advanceCatalog = self::getContainer()->get(AdvanceCatalog::class);
    }

    #[Test]
    public function getAdvancesLoadsAllFiftyOneAdvances(): void
    {
        $advances = $this->advanceCatalog->getAdvances();

        self::assertCount(51, $advances);
    }

    #[Test]
    public function anatomyHasItsKeyCostAndMitigation(): void
    {
        $advance = $this->advanceCatalog->getAdvanceByName('anatomy');

        self::assertSame('anatomy', $advance->key);
        self::assertSame(['epidemic'], $advance->mitigations);
        self::assertSame(270, $advance->cost);
    }

    #[Test]
    public function agricultureHasItsAggravationAndNamedCredit(): void
    {
        $advance = $this->advanceCatalog->getAdvanceByName('agriculture');

        self::assertSame(['famine'], $advance->aggravations);
        self::assertArrayHasKey('democracy', $advance->credits);
        self::assertSame(20, $advance->credits['democracy']);
    }

    #[Test]
    public function monarchyHasBothMitigationAndAggravation(): void
    {
        $advance = $this->advanceCatalog->getAdvanceByName('monarchy');

        self::assertSame(['barbarian_hordes'], $advance->mitigations);
        self::assertSame(['tyranny'], $advance->aggravations);
    }

    #[Test]
    public function architectureHasNoMitigationNorAggravation(): void
    {
        $advance = $this->advanceCatalog->getAdvanceByName('architecture');

        self::assertSame([], $advance->mitigations);
        self::assertSame([], $advance->aggravations);
    }

    #[Test]
    public function anatomyHasAGiftPromotion(): void
    {
        $advance = $this->advanceCatalog->getAdvanceByName('anatomy');

        self::assertInstanceOf(Promotion::class, $advance->promotion);
        self::assertSame(['science' => 100], $advance->promotion->gift);
        self::assertSame([], $advance->promotion->discount);
        self::assertNotInstanceOf(ElectiveBenefit::class, $advance->promotion->option);
    }

    #[Test]
    public function libraryHasADiscountPromotion(): void
    {
        $advance = $this->advanceCatalog->getAdvanceByName('library');

        self::assertInstanceOf(Promotion::class, $advance->promotion);
        self::assertSame(['any' => 40], $advance->promotion->discount);
        self::assertSame([], $advance->promotion->gift);
        self::assertNotInstanceOf(ElectiveBenefit::class, $advance->promotion->option);
    }

    #[Test]
    public function monumentHasAnOptionPromotionOfTwenty(): void
    {
        $advance = $this->advanceCatalog->getAdvanceByName('monument');

        self::assertInstanceOf(Promotion::class, $advance->promotion);
        self::assertInstanceOf(ElectiveBenefit::class, $advance->promotion->option);
        self::assertSame(20, $advance->promotion->option->budget);
        self::assertSame(5, $advance->promotion->option->step);
    }

    #[Test]
    public function writtenRecordHasAnOptionPromotionOfTen(): void
    {
        $advance = $this->advanceCatalog->getAdvanceByName('written_record');

        self::assertInstanceOf(Promotion::class, $advance->promotion);
        self::assertInstanceOf(ElectiveBenefit::class, $advance->promotion->option);
        self::assertSame(10, $advance->promotion->option->budget);
        self::assertSame(5, $advance->promotion->option->step);
    }

    #[Test]
    public function potteryHasNoPromotion(): void
    {
        $advance = $this->advanceCatalog->getAdvanceByName('pottery');

        self::assertNotInstanceOf(Promotion::class, $advance->promotion);
    }

    #[Test]
    public function getCategoryColorsReturnsExactlyTheFiveEnumCategoriesWithValidHexColors(): void
    {
        $colors = $this->advanceCatalog->getCategoryColors();

        $expectedKeys = array_map(static fn (Category $category): string => $category->value, Category::cases());
        self::assertSame($expectedKeys, array_keys($colors));

        foreach ($colors as $hex) {
            self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $hex);
        }
    }

    #[Test]
    public function getCategoryColorsReturnsTheOfficialHexValues(): void
    {
        $colors = $this->advanceCatalog->getCategoryColors();

        self::assertSame('#27AAE1', $colors['art']);
        self::assertSame('#F04E56', $colors['civic']);
        self::assertSame('#F7941E', $colors['craft']);
        self::assertSame('#FFF200', $colors['religion']);
        self::assertSame('#39B54A', $colors['science']);
    }
}
