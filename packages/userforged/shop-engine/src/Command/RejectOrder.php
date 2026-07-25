<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Command;

use Symfony\Component\Uid\Uuid;

final readonly class RejectOrder
{
    public function __construct(
        public Uuid $playerId,
        /** Opaque ordering-window index supplied by the host connector. */
        public int $window,
    ) {}
}
