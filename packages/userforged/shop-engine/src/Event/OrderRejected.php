<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Event;

use Symfony\Component\Uid\Uuid;

final readonly class OrderRejected
{
    public function __construct(
        public Uuid $playerId,
        public int $window,
    ) {}
}
