<?php

declare(strict_types=1);

namespace App\Tests\Support\Mercure;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

/**
 * Test double for the Mercure hub: no network I/O, no JWT signing, but every Update is kept so a
 * test can assert the exact sequence a code path publishes.
 * Registered in place of the real hub for the test environment (see config/services.yaml, when@test).
 *
 * The topic is the contract now — it names the screen region a signal wakes — so `regions()` is
 * what a test asserts on, not the payload, which carries nothing.
 */
final class RecordingHub implements HubInterface
{
    /** @var list<Update> */
    private array $updates = [];

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
        $this->updates[] = $update;

        return 'recording-hub-id';
    }

    /** @return list<Update> */
    public function updates(): array
    {
        return $this->updates;
    }

    /**
     * The regions woken, in publication order: what `empires/game/{id}/` is followed by, so a test
     * reads `roster` or `player/{uuid}/shop` instead of restating the whole topic.
     *
     * @return list<string>
     */
    public function regions(): array
    {
        return array_map(
            static fn (Update $update): string => explode('/', $update->getTopics()[0], 4)[3] ?? '',
            $this->updates,
        );
    }

    /** @return list<string> */
    public function topics(): array
    {
        return array_map(static fn (Update $update): string => $update->getTopics()[0], $this->updates);
    }

    /** Drops what the arrange phase published, so a test can assert on the act alone. */
    public function clear(): void
    {
        $this->updates = [];
    }
}
