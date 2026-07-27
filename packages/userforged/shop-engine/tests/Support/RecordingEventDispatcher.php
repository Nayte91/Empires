<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;

final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $eventClass
     *
     * @return list<T>
     */
    public function ofType(string $eventClass): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (object $event): bool => $event instanceof $eventClass,
        ));
    }
}
