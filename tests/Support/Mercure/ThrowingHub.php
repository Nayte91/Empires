<?php

declare(strict_types=1);

namespace App\Tests\Support\Mercure;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

/**
 * The attempt counter keeps the test honest: a substitution that failed to take would leave it green
 * for the wrong reason.
 */
final class ThrowingHub implements HubInterface
{
    public private(set) int $attempts = 0;

    public function getPublicUrl(): string
    {
        return '/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function publish(Update $update): string
    {
        ++$this->attempts;

        throw new \RuntimeException('Mercure hub unreachable.');
    }
}
