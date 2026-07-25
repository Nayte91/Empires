<?php

declare(strict_types=1);

namespace App\Shop\Exception;

final class CartException extends \DomainException implements ShopException
{
    private function __construct(
        string $message,
        private readonly ShopExceptionReason $shopReason,
    ) {
        parent::__construct($message);
    }

    public static function empty(): self
    {
        return new self('Cart is empty.', ShopExceptionReason::CartEmpty);
    }

    public function reason(): ShopExceptionReason
    {
        return $this->shopReason;
    }

    /** @return array<string, string> */
    public function context(): array
    {
        return [];
    }
}
