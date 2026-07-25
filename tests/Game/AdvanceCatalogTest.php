<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\AdvanceCatalog;
use App\Game\Category;
use App\Shop\Promotion\ElectiveBenefit;
use App\Shop\Promotion\ProductPromotion;
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

        $this->assertCount(51, $advances);
    }

    #[Test]
    public function anatomyHasItsKeyCostAndMitigation(): void
    {
        $advance = $this->advanceCatalog->getAdvanceByName('anatomy');

        $this->assertSame('anatomy', $advance->key);
        $this->assertSame(['epidemic'], $advance->mitigations);
        $this->assertSame(270, $advance->cost);
    }

    #[Test]
    public function agricultureHasItsAggravationAndNamedCredit(): void
    {
        $advance = $this->advanceCatalog->getAdvanceByName('agriculture');

        $this->assertSame(['famine'], $advance->aggravations);
        $this->assertArrayHasKey('democracy', $advance->credits);
        $this->assertSame(20, $advance->credits['democracy']);
    }

    #[Test]
    public function monarchyHasBothMitigationAndAggravation(): void
    {
        $advance = $this->advanceCatalog->getAdvanceByName('monarchy');

        $this->assertSame(['barbarian_hordes'], $advance->mitigations);
        $this->assertSame(['tyranny'], $advance->aggravations);
    }

    #[Test]
    public function architectureHasNoMitigationNorAggravation(): void
    {
        $advance = $this->advanceCatalog->getAdvanceByName('architecture');

        $this->assertSame([], $advance->mitigations);
        $this->assertSame([], $advance->aggravations);
    }

    #[Test]
    public function anatomyHasAGiftPromotion(): void
    {
        $advance = $this->advanceCatalog->getAdvanceByName('anatomy');

        $this->assertInstanceOf(ProductPromotion::class, $advance->promotion);
        $this->assertSame(['science' => 100], $advance->promotion->gift);
        $this->assertSame([], $advance->promotion->discount);
        $this->assertNotInstanceOf(ElectiveBenefit::class, $advance->promotion->option);
    }

    #[Test]
    public function libraryHasADiscountPromotion(): void
    {
        $advance = $this->advanceCatalog->getAdvanceByName('library');

        $this->assertInstanceOf(ProductPromotion::class, $advance->promotion);
        $this->assertSame(['any' => 40], $advance->promotion->discount);
        $this->assertSame([], $advance->promotion->gift);
        $this->assertNotInstanceOf(ElectiveBenefit::class, $advance->promotion->option);
    }

    #[Test]
    public function monumentHasAnOptionPromotionOfTwenty(): void
    {
        $advance = $this->advanceCatalog->getAdvanceByName('monument');

        $this->assertInstanceOf(ProductPromotion::class, $advance->promotion);
        $this->assertInstanceOf(ElectiveBenefit::class, $advance->promotion->option);
        $this->assertSame(20, $advance->promotion->option->budget);
        $this->assertSame(5, $advance->promotion->option->step);
    }

    #[Test]
    public function writtenRecordHasAnOptionPromotionOfTen(): void
    {
        $advance = $this->advanceCatalog->getAdvanceByName('written_record');

        $this->assertInstanceOf(ProductPromotion::class, $advance->promotion);
        $this->assertInstanceOf(ElectiveBenefit::class, $advance->promotion->option);
        $this->assertSame(10, $advance->promotion->option->budget);
        $this->assertSame(5, $advance->promotion->option->step);
    }

    #[Test]
    public function potteryHasNoPromotion(): void
    {
        $advance = $this->advanceCatalog->getAdvanceByName('pottery');

        $this->assertNotInstanceOf(ProductPromotion::class, $advance->promotion);
    }

    #[Test]
    public function getCategoryColorsReturnsExactlyTheFiveEnumCategoriesWithValidHexColors(): void
    {
        $colors = $this->advanceCatalog->getCategoryColors();

        $expectedKeys = array_map(static fn (Category $category): string => $category->value, Category::cases());
        $this->assertSame($expectedKeys, array_keys($colors));

        foreach ($colors as $hex) {
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $hex);
        }
    }

    #[Test]
    public function getCategoryColorsReturnsTheOfficialHexValues(): void
    {
        $colors = $this->advanceCatalog->getCategoryColors();

        $this->assertSame('#27AAE1', $colors['art']);
        $this->assertSame('#F04E56', $colors['civic']);
        $this->assertSame('#F7941E', $colors['craft']);
        $this->assertSame('#FFF200', $colors['religion']);
        $this->assertSame('#39B54A', $colors['science']);
    }
}
