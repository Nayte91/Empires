<?php

declare(strict_types=1);

namespace App\Shop\Command;

use App\Shop\Dto\LineIntent;
use Symfony\Component\Uid\Uuid;

final readonly class SubmitOrder
{
    /** @param list<LineIntent> $items */
    public function __construct(
        public Uuid $playerId,
        public array $items,
        /** Opaque ordering-window index supplied by the host connector. */
        public int $window,
    ) {}
}
