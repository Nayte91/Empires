<?php

declare(strict_types=1);

namespace App\Shop\Exception;

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
            sprintf('Advance "%s" is already owned.', $key),
            ShopExceptionReason::AdvanceAlreadyOwned,
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
