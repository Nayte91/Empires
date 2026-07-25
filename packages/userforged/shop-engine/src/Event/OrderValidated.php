<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Event;

use Symfony\Component\Uid\Uuid;

final readonly class OrderValidated
{
    public function __construct(
        public Uuid $buyerId,
        public int $window,
    ) {}
}
