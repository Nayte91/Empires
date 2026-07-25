<?php

declare(strict_types=1);

namespace App\Shop\CommandHandler;

use App\Shop\BuyerProviderInterface;
use App\Shop\Command\RejectOrder;
use App\Shop\Event\OrderRejected;
use App\Shop\Event\ShopEventPublisher;
use App\Shop\Exception\OrderException;
use App\Shop\OrderInterface;
use App\Shop\OrderRepositoryInterface;
use App\Shop\TransactionInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Workflow\WorkflowInterface;

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
        $this->buyers->buyerFor($command->playerId);

        $order = $this->orderRepository->findOneByBuyerAndWindow($command->playerId, $command->window);

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

        $this->events->publish(new OrderRejected($command->playerId, $command->window));
    }
}
