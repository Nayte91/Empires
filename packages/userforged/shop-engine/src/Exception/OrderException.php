<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Exception;

final class OrderException extends \DomainException implements ShopException
{
    private function __construct(
        string $message,
        private readonly ShopExceptionReason $shopReason,
    ) {
        parent::__construct($message);
    }

    public static function windowAlreadyValidated(): self
    {
        return new self('An order has already been validated.', ShopExceptionReason::WindowAlreadyValidated);
    }

    public static function alreadyValidated(): self
    {
        return new self('Order is already validated.', ShopExceptionReason::OrderAlreadyValidated);
    }

    public static function linesLocked(): self
    {
        return new self('Cannot replace lines of an already validated order.', ShopExceptionReason::OrderLinesLocked);
    }

    public static function rejectionUnavailable(): self
    {
        return new self('This order cannot be rejected.', ShopExceptionReason::OrderRejectionUnavailable);
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
