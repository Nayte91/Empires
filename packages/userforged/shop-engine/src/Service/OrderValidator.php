<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Service;

use Symfony\Component\Workflow\WorkflowInterface;
use Userforged\ShopEngine\BuyerProviderInterface;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\Event\OrderValidated;
use Userforged\ShopEngine\Event\ShopEventPublisher;
use Userforged\ShopEngine\Exception\EligibilityException;
use Userforged\ShopEngine\FulfillmentInterface;
use Userforged\ShopEngine\OrderInterface;
use Userforged\ShopEngine\TransactionInterface;

final readonly class OrderValidator
{
    public function __construct(
        private TransactionInterface $transaction,
        private LineQuoter $lineQuoter,
        private WorkflowInterface $shopOrderStateMachine,
        private BuyerProviderInterface $buyers,
        private ShopEventPublisher $events,
        private FulfillmentInterface $fulfillment,
    ) {}

    public function validate(OrderInterface $order): void
    {
        $slugs = $order->keys();

        $intents = $this->lineQuoter->intentsFromLines($order->lines());
        // Built right here, not before: the order is still Pending at this point,
        // so it's correctly excluded from its own elective credits — a
        // not-yet-validated order must never credit its own buyer. The buyer
        // now also feeds the eligibility check below, which is why that check
        // moved here instead of reading the buyer's owned-state directly from
        // the host's own domain model — one buyerFor() call serves both. Never
        // hoist this above the transition below, and never reuse a buyer
        // across it.
        $buyer = $this->buyers->buyerFor($order->buyerId);

        foreach ($slugs as $slug) {
            if (in_array($slug, $buyer->ownedKeys, true)) {
                throw EligibilityException::productAlreadyOwned($slug);
            }
        }

        $frozenLines = $this->lineQuoter->quote($intents, $buyer);
        $total = array_sum(array_map(static fn (OrderLine $line): int => $line->netCost, $frozenLines));

        $machine = $this->shopOrderStateMachine;
        $fulfillment = $this->fulfillment;

        $this->transaction->transactional(static function () use ($order, $frozenLines, $total, $machine, $fulfillment): void {
            $machine->apply($order, 'validate');
            $order->freeze($frozenLines, $total);
            $fulfillment->grant($order->buyerId, $order->keys(), $order->window);
        });

        // Published through afterCommit(), not right after transactional() returns:
        // when this call joins an outer scope (SellDirectHandler), a direct publish
        // here would fire before the outer commit — an event consumer could observe
        // a validated order before its SQL write had actually landed.
        $this->transaction->afterCommit(function () use ($order): void {
            $this->events->publish(new OrderValidated($order->buyerId, $order->window));
        });
    }
}
