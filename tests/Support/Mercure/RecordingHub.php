<?php

declare(strict_types=1);

namespace App\Tests\Support\Mercure;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

/**
 * Keeps every Update instead of publishing one; registered in place of the real hub under
 * `when@test` (config/services.yaml). Assert on regions(), never on the payload.
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
     * What follows `empires/game/{id}/` in each topic published, in order.
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

    public function clear(): void
    {
        $this->updates = [];
    }
}
