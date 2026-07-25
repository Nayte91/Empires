<?php

declare(strict_types=1);

namespace Userforged\ShopEngine;

use Symfony\Component\Uid\Uuid;
use Userforged\ShopEngine\Dto\OrderLine;

/**
 * The lib's view of an order: buyer ref, window, status, lines, plus the
 * marking-store contract (port). The host persists it (e.g. as a
 * Doctrine-mapped entity).
 *
 * getMarking()/setMarking() are here even though no library code calls them
 * directly: config/workflow.yaml declares
 * `marking_store: { type: method, property: marking }`, so the shop_order
 * state machine — library-owned — needs them to operate on any
 * implementation. Omitting them would let a second host pass every other
 * member and still fail at workflow apply() time.
 *
 * `window`, not `turn`: every other library symbol says "window"
 * (SubmitOrder->window, findOneByBuyerAndWindow) — `turn` is the host's
 * game vocabulary and has no business leaking into this interface.
 *
 * `id`, `total`, `validatedAt`, `createdAt` are deliberately OFF this
 * interface: verified unread by any library code. The README's target
 * OrderInterface entry lists `total` and timestamps, but "nothing
 * speculative" wins — they ship when a library caller actually needs them,
 * not before.
 */
interface OrderInterface
{
    public Uuid $buyerId { get; }
    public int $window { get; }
    public OrderStatus $status { get; }

    /** @return list<OrderLine> */
    public function lines(): array;

    /** @return list<string> */
    public function keys(): array;

    /** @param list<OrderLine> $lines */
    public function replaceLines(array $lines): void;

    /** @param list<OrderLine> $lines */
    public function freeze(array $lines, int $total): void;

    /** Workflow marking store access — status writes go through the shop_order state machine. */
    public function getMarking(): string;

    /**
     * Workflow marking store access — status writes go through the shop_order state machine.
     *
     * @param array<string, mixed> $context
     */
    public function setMarking(string $marking, array $context = []): void;
}
