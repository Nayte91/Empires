<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\CommandHandler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Workflow\WorkflowInterface;
use Userforged\ShopEngine\BuyerProviderInterface;
use Userforged\ShopEngine\Command\RejectOrder;
use Userforged\ShopEngine\Event\OrderRejected;
use Userforged\ShopEngine\Event\ShopEventPublisher;
use Userforged\ShopEngine\Exception\OrderException;
use Userforged\ShopEngine\OrderInterface;
use Userforged\ShopEngine\OrderRepositoryInterface;
use Userforged\ShopEngine\TransactionInterface;

#[AsMessageHandler]
final readonly class RejectOrderHandler
{
    public function __construct(
        private TransactionInterface $transaction,
        private OrderRepositoryInterface $orderRepository,
        private BuyerProviderInterface $buyers,
        private WorkflowInterface $shopOrderStateMachine,
        private ShopEventPublisher $events,
    ) {}

    public function __invoke(RejectOrder $command): void
    {
        $this->buyers->buyerFor($command->buyerId);

        $order = $this->orderRepository->findOneByBuyerAndWindow($command->buyerId, $command->window);

        if (!$order instanceof OrderInterface) {
            return;
        }

        if (!$this->shopOrderStateMachine->can($order, 'reject')) {
            throw OrderException::rejectionUnavailable();
        }

        $machine = $this->shopOrderStateMachine;
        $this->transaction->transactional(static function () use ($order, $machine): void {
            $machine->apply($order, 'reject');
        });

        $this->events->publish(new OrderRejected($command->buyerId, $command->window));
    }
}
