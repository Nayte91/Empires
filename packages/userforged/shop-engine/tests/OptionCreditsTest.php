<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests;

use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\Promotion\AppliedPromotion;
use Userforged\ShopEngine\Promotion\OptionCredits;
use Userforged\ShopEngine\Promotion\PromotionType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OptionCreditsTest extends TestCase
{
    #[Test]
    public function aggregateSumsAllocationsFromALineByFacet(): void
    {
        $lines = [$this->optionLine('monument', ['craft' => 10, 'science' => 10])];

        $this->assertSame(['craft' => 10, 'science' => 10], OptionCredits::aggregate($lines));
    }

    #[Test]
    public function aggregateCumulatesAllocationsAcrossSeveralLines(): void
    {
        $lines = [
            $this->optionLine('monument', ['craft' => 10, 'science' => 10]),
            $this->optionLine('temple', ['craft' => 5]),
        ];

        $this->assertSame(['craft' => 15, 'science' => 10], OptionCredits::aggregate($lines));
    }

    #[Test]
    public function aggregateIgnoresLinesWithoutAnOptionPromotion(): void
    {
        $lines = [
            new OrderLine('pottery', 50),
            new OrderLine('library', 40, new AppliedPromotion(PromotionType::Discount, 'democracy', amount: 40)),
            new OrderLine('astronavigation', 0, new AppliedPromotion(PromotionType::Gift, 'anatomy')),
        ];

        $this->assertSame([], OptionCredits::aggregate($lines));
    }

    #[Test]
    public function aggregateWithNoLinesReturnsEmptyArray(): void
    {
        $this->assertSame([], OptionCredits::aggregate([]));
    }

    /** @param array<string, int> $allocation */
    private function optionLine(string $key, array $allocation): OrderLine
    {
        return new OrderLine($key, 180, new AppliedPromotion(PromotionType::Option, $key, allocation: $allocation));
    }
}
