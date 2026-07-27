<?php

declare(strict_types=1);

namespace App\Rules\Action;

use Symfony\Component\Uid\Uuid;

final readonly class SetStat
{
    public function __construct(
        public Uuid $playerId,
        public Stat $stat,
        public int $value,
    ) {}
}
