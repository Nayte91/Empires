<?php

declare(strict_types=1);

namespace Userforged\ShopEngine;

use Userforged\ShopEngine\Dto\LineIntent;

final class Cart
{
    /** @var list<LineIntent> */
    public array $items = [];

    /** @param list<string> $keys */
    public static function fromKeys(array $keys): self
    {
        $cart = new self();
        $cart->items = array_map(static fn (string $key): LineIntent => new LineIntent($key), $keys);

        return $cart;
    }

    public function add(string $key): void
    {
        if ($this->has($key)) {
            return;
        }

        $this->items[] = new LineIntent($key);
    }

    /**
     * Two-way cascade: removing a gift's source also removes the gift target's
     * own intent (unless another intent still points to it via ->gift), and
     * removing a gift's target clears the dangling ->gift pointer on whatever
     * intent still references it — otherwise PromotionEngine::applyGifts()
     * would silently re-derive and re-append the exact same gift line on the
     * next render, but now without its own allocation.
     */
    public function remove(string $key): void
    {
        $intent = $this->find($key);

        $this->items = array_values(array_filter(
            $this->items,
            static fn (LineIntent $item): bool => $item->key !== $key,
        ));

        if ($intent instanceof LineIntent && null !== $intent->gift && !$this->isReferencedAsGift($intent->gift)) {
            $this->remove($intent->gift);
        }

        $this->items = array_map(
            static fn (LineIntent $item): LineIntent => $item->gift === $key
                ? new LineIntent($item->key, null, $item->allocation)
                : $item,
            $this->items,
        );
    }

    public function clear(): void
    {
        $this->items = [];
    }

    public function has(string $key): bool
    {
        return $this->find($key) instanceof LineIntent;
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(static fn (LineIntent $item): string => $item->key, $this->items);
    }

    public function stamp(): string
    {
        return md5(json_encode(array_map(static fn (LineIntent $item): array => $item->toArray(), $this->items), JSON_THROW_ON_ERROR));
    }

    /**
     * Choosing/changing/revoking a gift keeps the target's own intent in
     * sync: a newly chosen gift gets its own intent appended (if missing),
     * and a stale gift's intent is removed once nothing references it
     * anymore — see the two-way cascade in remove().
     */
    public function withGift(string $for, ?string $gift): void
    {
        $intent = $this->find($for);

        if (!$intent instanceof LineIntent) {
            return;
        }

        $oldGift = $intent->gift;

        $this->items = array_map(
            static fn (LineIntent $item): LineIntent => $item->key === $for
                ? new LineIntent($item->key, $gift, $item->allocation)
                : $item,
            $this->items,
        );

        if (null !== $gift && !$this->has($gift)) {
            $this->items[] = new LineIntent($gift);
        }

        if (null !== $oldGift && $oldGift !== $gift && !$this->isReferencedAsGift($oldGift)) {
            $this->remove($oldGift);
        }
    }

    /** Sum-completeness is validated by PromotionEngine; this only clamps a single facet to zero or above. */
    public function withAllocation(string $key, string $facet, int $delta): void
    {
        $intent = $this->find($key);

        if (!$intent instanceof LineIntent) {
            return;
        }

        $allocation = $intent->allocation;
        $allocation[$facet] = max(0, ($allocation[$facet] ?? 0) + $delta);

        $this->items = array_map(
            static fn (LineIntent $item): LineIntent => $item->key === $key
                ? new LineIntent($item->key, $item->gift, $allocation)
                : $item,
            $this->items,
        );
    }

    private function find(string $key): ?LineIntent
    {
        foreach ($this->items as $item) {
            if ($item->key === $key) {
                return $item;
            }
        }

        return null;
    }

    private function isReferencedAsGift(string $key): bool
    {
        return array_any($this->items, static fn (LineIntent $item): bool => $item->gift === $key);
    }
}
