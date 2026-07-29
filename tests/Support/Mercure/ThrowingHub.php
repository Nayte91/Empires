<?php

declare(strict_types=1);

namespace App\Tests\Support\Mercure;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

/**
 * The unreachable hub, substituted for {@see RecordingHub} to pin the publisher's try/catch.
 * The attempt counter keeps that test honest: a substitution that failed to take would otherwise
 * leave it green for the wrong reason.
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
