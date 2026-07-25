<?php

declare(strict_types=1);

namespace App\Shop\CommandHandler;

use App\Shop\BuyerProviderInterface;
use App\Shop\Command\SubmitOrder;
use App\Shop\Event\OrderSubmitted;
use App\Shop\Event\ShopEventPublisher;
use App\Shop\Exception\CartException;
use App\Shop\Exception\EligibilityException;
use App\Shop\Exception\OrderException;
use App\Shop\OrderInterface;
use App\Shop\OrderRepositoryInterface;
use App\Shop\OrderStatus;
use App\Shop\Service\LineQuoter;
use App\Shop\TransactionInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Workflow\WorkflowInterface;

#[AsMessageHandler]
final readonly class SubmitOrderHandler
{
    public function __construct(
        private TransactionInterface $transaction,
        private OrderRepositoryInterface $orderRepository,
        private LineQuoter $lineQuoter,
        private WorkflowInterface $shopOrderStateMachine,
        private ShopEventPublisher $events,
        private BuyerProviderInterface $buyers,
    ) {}

    public function __invoke(SubmitOrder $command): OrderInterface
    {
        if ([] === $command->items) {
            throw CartException::empty();
        }

        $buyer = $this->buyers->buyerFor($command->playerId);

        foreach ($command->items as $item) {
            if (in_array($item->key, $buyer->ownedKeys, true)) {
                throw EligibilityException::productAlreadyOwned($item->key);
            }
        }

        $existing = $this->orderRepository->findOneByBuyerAndWindow($command->playerId, $command->window);

        if (OrderStatus::Validated === $existing?->status) {
            throw OrderException::windowAlreadyValidated();
        }

        // Quoted before the order row exists: if this throws (PromotionException on
        // a bad elective allocation), no persisted-but-unflushed empty Order is left
        // dangling in the unit of work for a later flush() to insert.
        $lines = $this->lineQuoter->quote($command->items, $buyer);
        $order = $existing ?? $this->orderRepository->create($command->playerId, $command->window);

        $this->transaction->transactional(function () use ($order, $lines): void {
            $order->replaceLines($lines);

            // Canon rule: submitting onto a rejected slot reopens it.
            if (OrderStatus::Rejected === $order->status) {
                $this->shopOrderStateMachine->apply($order, 'resubmit');
            }
        });

        $this->events->publish(new OrderSubmitted($command->playerId, $command->window));

        return $order;
    }
}
