<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Command;

use Symfony\Component\Uid\Uuid;
use Userforged\ShopEngine\Dto\LineIntent;

final readonly class SellDirect
{
    /** @param list<LineIntent> $items */
    public function __construct(
        public Uuid $buyerId,
        public array $items,
        /** Opaque ordering-window index supplied by the host connector. */
        public int $window,
    ) {}
}
