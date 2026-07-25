<?php

declare(strict_types=1);

namespace App\Shop\Exception;

final class PromotionException extends \DomainException implements ShopException
{
    public static function invalidGift(string $key): self
    {
        return new self(sprintf('Invalid gift promotion for "%s".', $key));
    }

    public static function allocationRequired(string $key): self
    {
        return new self(sprintf('Allocation required for promotion on "%s".', $key));
    }

    public static function invalidAllocation(string $key): self
    {
        return new self(sprintf('Invalid allocation for promotion on "%s".', $key));
    }
}
