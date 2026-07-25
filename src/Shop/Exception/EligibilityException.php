<?php

declare(strict_types=1);

namespace App\Shop\Exception;

final class EligibilityException extends \DomainException implements ShopException
{
    public static function productAlreadyOwned(string $key): self
    {
        return new self(sprintf('Advance "%s" is already owned.', $key));
    }
}
