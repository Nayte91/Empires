<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Presentation\Shop\CartItemAdder;
use App\Presentation\Shop\CartKey;
use App\Presentation\Shop\CatalogView;
use App\Presentation\Shop\ShopExceptionTranslator;
use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Shop\ShopConnector;
use App\State\Order;
use App\State\Player;
use App\State\Repository\OrderRepositoryInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\Exception\ShopExceptionReason;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Service\LineQuoter;

#[AsLiveComponent(template: 'organisms/shop.html.twig')]
final class Shop
{
    use DefaultActionTrait;
    use OrderRowsTrait;

    #[LiveProp]
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    /**
     * Planning aid only — never persisted, never reaching Engine/ or the command bus, never
     * affecting checkout. Null means no constraint, distinct from a budget of 0 (see
     * {@see getRemainingBudget()}); LiveComponent itself coerces an emptied number input to null
     * for a nullable int prop, so no extra handling is needed here.
     */
    #[LiveProp(writable: true)]
    public ?int $budget = null;

    public ?string $error = null;
    private bool $currentOrderLoaded = false;
    private ?Order $currentOrder = null;

    public function __construct(
        private readonly AdvanceRegistry $advanceRegistry,
        private readonly CartItemAdder $cartItemAdder,
        private readonly CartStorageInterface $cartStorage,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LineQuoter $lineQuoter,
        private readonly ShopConnector $shopConnector,
        private readonly ShopExceptionTranslator $shopExceptionTranslator,
    ) {}

    /** Re-renders Shop so the order block reflects the order Cart::checkout() just placed. */
    #[LiveListener('orderPlaced')]
    public function onOrderPlaced(): void {}

    /** Re-renders Shop so the catalogue puts back what Cart::remove() or Cart::clear() released. */
    #[LiveListener('cartChanged')]
    public function onCartChanged(): void {}

    #[LiveAction]
    public function add(#[LiveArg] string $key): void
    {
        if ($this->isLockedForTurn()) {
            $this->error = $this->shopExceptionTranslator->messageForReason(ShopExceptionReason::WindowAlreadyValidated);

            return;
        }

        $this->error = $this->cartItemAdder->add($this->player, $this->getCartKey(), $key);
    }

    #[LiveAction]
    public function editPendingOrder(): void
    {
        $order = $this->getPendingOrder();

        if (!$order instanceof Order) {
            return;
        }

        $cart = new Cart();
        $cart->items = $this->lineQuoter->intentsFromLines($order->lines());

        $this->cartStorage->save($this->getCartKey(), $cart);
    }

    /** Editable order for the current turn — a rejected order reopens for revision, resubmitting it. */
    public function getPendingOrder(): ?Order
    {
        $order = $this->getCurrentTurnOrder();
        $editable = $order instanceof Order
            && (OrderStatus::Pending === $order->status || OrderStatus::Rejected === $order->status);

        return $editable ? $order : null;
    }

    public function isCartEmpty(): bool
    {
        $cart = $this->cartStorage->load($this->getCartKey());

        return $cart->isEmpty();
    }

    /** @return list<array{advance: Advance, line: OrderLine}> */
    public function getOrderLines(): array
    {
        $order = $this->getCurrentTurnOrder();

        if (!$order instanceof Order) {
            return [];
        }

        $lines = OrderStatus::Validated === $order->status
            ? $order->lines()
            : $this->lineQuoter->quote($this->lineQuoter->intentsFromLines($order->lines()), $this->shopConnector->buyerFor($this->player));

        return $this->toRows($lines, $this->advanceRegistry);
    }

    public function getOrderTotal(): int
    {
        $order = $this->getCurrentTurnOrder();

        if ($order instanceof Order && OrderStatus::Validated === $order->status) {
            return $order->total ?? 0;
        }

        return $this->sumNetCost($this->getOrderLines());
    }

    public function isLockedForTurn(): bool
    {
        return OrderStatus::Validated === $this->getCurrentTurnOrder()?->status;
    }

    /** Status of the order for the current turn — only meaningful while {@see isOrderVisible()} is true. */
    public function getOrderStatus(): ?string
    {
        return $this->getCurrentTurnOrder()?->status->value;
    }

    public function isCartVisible(): bool
    {
        return !$this->getCurrentTurnOrder() instanceof Order || !$this->isCartEmpty();
    }

    public function isOrderVisible(): bool
    {
        return $this->getCurrentTurnOrder() instanceof Order && !$this->isCartVisible();
    }

    public function getCartStamp(): string
    {
        return $this->cartStorage->load($this->getCartKey())->stamp();
    }

    /**
     * Null when no budget is set. Deliberately raw and possibly negative: Catalog uses it as-is
     * to decide which cards disable (a negative remainder must still disable every card); only
     * the figure shown in the template is clamped at zero.
     */
    public function getRemainingBudget(): ?int
    {
        return null === $this->budget ? null : $this->budget - $this->getCartTotal();
    }

    /** Sorted by what this player actually pays: two thirds of the catalogue is discounted, so
     * the list-price order the shelf inherits contradicts the price printed on most of its tiles. */
    public function getCatalogView(): CatalogView
    {
        return CatalogView::kiosk($this->isLockedForTurn(), $this->getRemainingBudget());
    }

    /** Public for organisms/shop, which hands it to the nested Catalog and Cart as their storageKey. */
    public function getCartKey(): string
    {
        return CartKey::shop($this->player);
    }

    /** Mirrors Cart::getTotal()'s own computation — the two components run independently and can't share an instance, so this reuses the same trait methods rather than inventing a second way to total a cart. */
    private function getCartTotal(): int
    {
        $cart = $this->cartStorage->load($this->getCartKey());
        $lines = $this->lineQuoter->quotePreview($cart->items, $this->shopConnector->buyerFor($this->player));

        return $this->sumNetCost($this->toRows($lines, $this->advanceRegistry));
    }

    private function getCurrentTurnOrder(): ?Order
    {
        if (!$this->currentOrderLoaded) {
            $this->currentOrder = $this->orderRepository->findOneByPlayerAndWindow(
                $this->player,
                $this->player->game->currentTurn,
            );
            $this->currentOrderLoaded = true;
        }

        return $this->currentOrder;
    }
}
