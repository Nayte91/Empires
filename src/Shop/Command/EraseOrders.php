<?php

declare(strict_types=1);

namespace App\Shop\Command;

use Symfony\Component\Uid\Uuid;

final readonly class EraseOrders
{
    /** @param list<int> $windows Opaque ordering-window indexes supplied by the host connector. */
    public function __construct(
        public Uuid $playerId,
        public array $windows,
    ) {}
}
