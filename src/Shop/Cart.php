<?php

declare(strict_types=1);

namespace App\Shop;

final class Cart
{
    /** @var list<string> */
    public array $items = [];

    public function add(string $key): void
    {
        if ($this->has($key)) {
            return;
        }

        $this->items[] = $key;
    }

    public function remove(string $key): void
    {
        $this->items = array_values(array_filter(
            $this->items,
            static fn (string $item): bool => $item !== $key,
        ));
    }

    public function clear(): void
    {
        $this->items = [];
    }

    public function has(string $key): bool
    {
        return in_array($key, $this->items, true);
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }
}
