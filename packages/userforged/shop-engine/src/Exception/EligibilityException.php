<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Exception;

final class EligibilityException extends \DomainException implements ShopException
{
    /** @param array<string, string> $shopContext */
    private function __construct(
        string $message,
        private readonly ShopExceptionReason $shopReason,
        private readonly array $shopContext,
    ) {
        parent::__construct($message);
    }

    public static function productAlreadyOwned(string $key): self
    {
        return new self(
            sprintf('Product "%s" is already owned.', $key),
            ShopExceptionReason::ProductAlreadyOwned,
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
