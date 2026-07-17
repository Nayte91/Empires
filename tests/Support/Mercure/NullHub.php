<?php

declare(strict_types=1);

namespace App\Tests\Support\Mercure;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

/**
 * Test double for the Mercure hub: no network I/O, no JWT signing.
 * Registered in place of the real hub for the test environment (see
 * config/services.yaml, when@test) so OrderSubmitter/OrderValidator's
 * publish() calls stay in-process during the PHPUnit suite.
 */
final class NullHub implements HubInterface
{
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
        return 'null-hub-id';
    }
}
