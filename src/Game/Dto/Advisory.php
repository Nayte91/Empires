<?php

declare(strict_types=1);

namespace App\Game\Dto;

final readonly class Advisory
{
    public function __construct(
        public string $message,
    ) {}
}
