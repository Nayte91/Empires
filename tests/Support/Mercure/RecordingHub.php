<?php

declare(strict_types=1);

namespace App\Tests\Support\Mercure;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

/**
 * Test double for the Mercure hub: no network I/O, no JWT signing, but every
 * Update is kept so a test can assert the exact sequence a code path publishes.
 * Registered in place of the real hub for the test environment (see
 * config/services.yaml, when@test).
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
     * Event names in publication order, decoded from the `{"event":"…"}` payloads.
     *
     * @return list<string>
     */
    public function eventNames(): array
    {
        return array_map(
            static fn (Update $update): string => json_decode($update->getData(), true, 512, \JSON_THROW_ON_ERROR)['event'],
            $this->updates,
        );
    }

    /**
     * One topic per recorded Update — the Shop paths always publish to a single topic.
     *
     * @return list<string>
     */
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
