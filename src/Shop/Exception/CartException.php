<?php

declare(strict_types=1);

namespace App\Shop\Exception;

final class CartException extends \DomainException implements ShopException
{
    public static function empty(): self
    {
        return new self('Cart is empty.');
    }
}
