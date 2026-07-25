<?php

declare(strict_types=1);

namespace App\Shop\CommandHandler;

use App\Shop\BuyerProviderInterface;
use App\Shop\Command\EraseOrders;
use App\Shop\Event\OrdersErased;
use App\Shop\Event\ShopEventPublisher;
use App\Shop\FulfillmentInterface;
use App\Shop\OrderInterface;
use App\Shop\OrderRepositoryInterface;
use App\Shop\OrderStatus;
use App\Shop\TransactionInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class EraseOrdersHandler
{
    public function __construct(
        private TransactionInterface $transaction,
        private OrderRepositoryInterface $orderRepository,
        private BuyerProviderInterface $buyers,
        private ShopEventPublisher $events,
        private FulfillmentInterface $fulfillment,
    ) {}

    public function __invoke(EraseOrders $command): void
    {
        if ([] === $command->windows) {
            return;
        }

        $this->buyers->buyerFor($command->playerId);

        $orders = array_values(array_filter(array_map(
            fn (int $window): ?OrderInterface => $this->orderRepository->findOneByBuyerAndWindow($command->playerId, $window),
            $command->windows,
        )));

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
            $command->playerId,
            array_map(static fn (OrderInterface $order): int => $order->window, $orders),
        ));
    }
}
