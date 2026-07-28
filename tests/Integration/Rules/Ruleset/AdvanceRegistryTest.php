<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules\Ruleset;

use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Ruleset\Category;
use Userforged\ShopEngine\Promotion\ElectiveBenefit;
use Userforged\ShopEngine\Promotion\ProductPromotion;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdvanceRegistryTest extends WebTestCase
{
    private AdvanceRegistry $advanceRegistry;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->advanceRegistry = self::getContainer()->get(AdvanceRegistry::class);
    }

    #[Test]
    public function getAdvancesLoadsAllFiftyOneAdvances(): void
    {
        $advances = $this->advanceRegistry->getAdvances();

        $this->assertCount(51, $advances);
    }

    #[Test]
    public function anatomyHasItsKeyCostAndMitigation(): void
    {
        $advance = $this->advanceRegistry->getAdvanceByName('anatomy');

        $this->assertSame('anatomy', $advance->key);
        $this->assertSame(['epidemic'], $advance->mitigations);
        $this->assertSame(270, $advance->cost);
    }

    #[Test]
    public function agricultureHasItsAggravationAndNamedCredit(): void
    {
        $advance = $this->advanceRegistry->getAdvanceByName('agriculture');

        $this->assertSame(['famine'], $advance->aggravations);
        $this->assertArrayHasKey('democracy', $advance->credits);
        $this->assertSame(20, $advance->credits['democracy']);
    }

    #[Test]
    public function architectureHasNoMitigationNorAggravation(): void
    {
        $advance = $this->advanceRegistry->getAdvanceByName('architecture');

        $this->assertSame([], $advance->mitigations);
        $this->assertSame([], $advance->aggravations);
    }

    #[Test]
    public function anatomyHasAGiftPromotion(): void
    {
        $advance = $this->advanceRegistry->getAdvanceByName('anatomy');

        $this->assertInstanceOf(ProductPromotion::class, $advance->promotion);
        $this->assertSame(['science' => 100], $advance->promotion->gift);
        $this->assertSame([], $advance->promotion->discount);
        $this->assertNotInstanceOf(ElectiveBenefit::class, $advance->promotion->option);
    }

    #[Test]
    public function libraryHasADiscountPromotion(): void
    {
        $advance = $this->advanceRegistry->getAdvanceByName('library');

        $this->assertInstanceOf(ProductPromotion::class, $advance->promotion);
        $this->assertSame(['any' => 40], $advance->promotion->discount);
        $this->assertSame([], $advance->promotion->gift);
        $this->assertNotInstanceOf(ElectiveBenefit::class, $advance->promotion->option);
    }

    #[Test]
    public function monumentHasAnOptionPromotionOfTwenty(): void
    {
        $advance = $this->advanceRegistry->getAdvanceByName('monument');

        $this->assertInstanceOf(ProductPromotion::class, $advance->promotion);
        $this->assertInstanceOf(ElectiveBenefit::class, $advance->promotion->option);
        $this->assertSame(20, $advance->promotion->option->budget);
        $this->assertSame(5, $advance->promotion->option->step);
    }

    #[Test]
    public function potteryHasNoPromotion(): void
    {
        $advance = $this->advanceRegistry->getAdvanceByName('pottery');

        $this->assertNotInstanceOf(ProductPromotion::class, $advance->promotion);
    }

    #[Test]
    public function getCategoryColorsReturnsExactlyTheFiveEnumCategoriesWithValidHexColors(): void
    {
        $colors = $this->advanceRegistry->getCategoryColors();

        $expectedKeys = array_map(static fn (Category $category): string => $category->value, Category::cases());
        $this->assertSame($expectedKeys, array_keys($colors));

        foreach ($colors as $hex) {
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $hex);
        }
    }

}
