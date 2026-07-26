<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\CommandHandler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Userforged\ShopEngine\Command\EraseOrders;
use Userforged\ShopEngine\Event\OrdersErased;
use Userforged\ShopEngine\Event\ShopEventPublisher;
use Userforged\ShopEngine\FulfillmentInterface;
use Userforged\ShopEngine\OrderInterface;
use Userforged\ShopEngine\OrderRepositoryInterface;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\TransactionInterface;

#[AsMessageHandler]
final readonly class EraseOrdersHandler
{
    public function __construct(
        private TransactionInterface $transaction,
        private OrderRepositoryInterface $orderRepository,
        private ShopEventPublisher $events,
        private FulfillmentInterface $fulfillment,
    ) {}

    public function __invoke(EraseOrders $command): void
    {
        if ([] === $command->windows) {
            return;
        }

        $orders = array_map(
                fn(int $window): ?OrderInterface => $this->orderRepository->findOneByBuyerAndWindow($command->buyerId, $window),
                $command->windows,
            )
                |> array_filter(...)
                |> array_values(...);

        if ([] === $orders) {
            return;
        }

        $orderRepository = $this->orderRepository;
        $fulfillment = $this->fulfillment;

        $this->transaction->transactional(static function () use ($orders, $orderRepository, $fulfillment): void {
            foreach ($orders as $o) {
                if (OrderStatus::Validated === $o->status) {
                    $fulfillment->revoke($o->buyerId, $o->keys());
                }

                $orderRepository->remove($o);
            }
        });

        $this->events->publish(new OrdersErased(
            $command->buyerId,
            array_map(static fn (OrderInterface $order): int => $order->window, $orders),
        ));
    }
}
