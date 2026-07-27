<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests\Support;

use Symfony\Component\Uid\Uuid;
use Userforged\ShopEngine\OrderInterface;
use Userforged\ShopEngine\OrderRepositoryInterface;

final class FakeOrderRepository implements OrderRepositoryInterface
{
    /** @var list<OrderInterface> */
    public array $removed = [];

    public int $created = 0;

    /** @param list<FakeOrder> $orders */
    public function __construct(private array $orders = []) {}

    public function findOneByBuyerAndWindow(Uuid $buyerId, int $window): ?OrderInterface
    {
        foreach ($this->orders as $order) {
            if ($order->buyerId->equals($buyerId) && $order->window === $window) {
                return $order;
            }
        }

        return null;
    }

    public function create(Uuid $buyerId, int $window): OrderInterface
    {
        ++$this->created;

        $order = new FakeOrder($buyerId, $window);
        $this->orders[] = $order;

        return $order;
    }

    public function remove(OrderInterface $order): void
    {
        $this->removed[] = $order;

        $this->orders = array_values(array_filter(
            $this->orders,
            static fn (OrderInterface $candidate): bool => $candidate !== $order,
        ));
    }
}
