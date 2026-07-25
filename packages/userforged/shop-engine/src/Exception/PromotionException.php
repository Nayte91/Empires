<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Exception;

final class PromotionException extends \DomainException implements ShopException
{
    /** @param array<string, string> $shopContext */
    private function __construct(
        string $message,
        private readonly ShopExceptionReason $shopReason,
        private readonly array $shopContext,
    ) {
        parent::__construct($message);
    }

    public static function invalidGift(string $key): self
    {
        return new self(
            sprintf('Invalid gift promotion for "%s".', $key),
            ShopExceptionReason::PromotionInvalidGift,
            ['key' => $key],
        );
    }

    public static function allocationRequired(string $key): self
    {
        return new self(
            sprintf('Allocation required for promotion on "%s".', $key),
            ShopExceptionReason::PromotionAllocationRequired,
            ['key' => $key],
        );
    }

    public static function invalidAllocation(string $key): self
    {
        return new self(
            sprintf('Invalid allocation for promotion on "%s".', $key),
            ShopExceptionReason::PromotionInvalidAllocation,
            ['key' => $key],
        );
    }

    public function reason(): ShopExceptionReason
    {
        return $this->shopReason;
    }

    /** @return array<string, string> */
    public function context(): array
    {
        return $this->shopContext;
    }
}
