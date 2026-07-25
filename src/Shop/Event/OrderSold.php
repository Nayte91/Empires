<?php

declare(strict_types=1);

namespace App\Shop\Event;

use Symfony\Component\Uid\Uuid;

final readonly class OrderSold
{
    public function __construct(
        public Uuid $playerId,
        public int $window,
    ) {}
}
