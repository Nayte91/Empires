<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests\Support;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderInterface;
use Userforged\ShopEngine\OrderStatus;

/**
 * An order that lives in memory. Its status is derived from the marking
 * rather than stored beside it, which is the whole point: OrderInterface
 * documents that status writes go through the shop_order state machine, so a
 * fake holding an independently settable status would let a handler "change"
 * a status the real contract forbids it from touching.
 */
final class FakeOrder implements OrderInterface
{
    public ?int $frozenTotal = null;

    public OrderStatus $status {
        get => OrderStatus::from($this->marking);
    }

    /** @param list<OrderLine> $lines */
    public function __construct(
        public Uuid $buyerId = new UuidV4(),
        public int $window = 1,
        private string $marking = 'pending',
        private array $lines = [],
    ) {}

    /** @return list<OrderLine> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(static fn (OrderLine $line): string => $line->key, $this->lines);
    }

    /** @param list<OrderLine> $lines */
    public function replaceLines(array $lines): void
    {
        $this->lines = $lines;
    }

    /** @param list<OrderLine> $lines */
    public function freeze(array $lines, int $total): void
    {
        $this->lines = $lines;
        $this->frozenTotal = $total;
    }

    public function getMarking(): string
    {
        return $this->marking;
    }

    /** @param array<string, mixed> $context */
    public function setMarking(string $marking, array $context = []): void
    {
        $this->marking = $marking;
    }
}
