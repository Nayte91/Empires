<?php

declare(strict_types=1);

namespace App\Shop\Exception;

final class OrderException extends \DomainException implements ShopException
{
    public static function windowAlreadyValidated(): self
    {
        return new self('An order has already been validated.');
    }

    public static function alreadyValidated(): self
    {
        return new self('Order is already validated.');
    }

    public static function linesLocked(): self
    {
        return new self('Cannot replace lines of an already validated order.');
    }

    public static function rejectionUnavailable(): self
    {
        return new self('This order cannot be rejected.');
    }
}
