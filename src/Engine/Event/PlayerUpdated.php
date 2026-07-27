<?php

declare(strict_types=1);

namespace App\Engine\Event;

use Symfony\Component\Uid\Uuid;

final readonly class PlayerUpdated
{
    public function __construct(public Uuid $playerId) {}
}
