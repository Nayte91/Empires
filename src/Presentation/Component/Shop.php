<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Presentation\Shop\CartItemAdder;
use App\Presentation\Shop\CartKey;
use App\Presentation\Shop\CatalogSort;
use App\Presentation\Shop\CatalogView;
use App\Presentation\Shop\ShopExceptionTranslator;
use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Shop\ShopConnector;
use App\State\Order;
use App\State\Player;
use App\State\Repository\OrderRepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Command\EraseOrders;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\Exception\ShopExceptionReason;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Service\LineQuoter;

#[AsLiveComponent(template: 'organisms/Shop.html.twig')]
final class Shop
{
    use DefaultActionTrait;
    use OrderRowsTrait;

    #[LiveProp]
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp(writable: true)]
    public ?int $budget = null;

    #[LiveProp(writable: true)]
    public CatalogSort $sort = CatalogSort::NetPrice;

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
        private readonly MessageBusInterface $commandBus,
        private readonly ShopExceptionTranslator $shopExceptionTranslator,
    ) {}

    #[LiveListener('orderPlaced')]
    public function onOrderPlaced(): void {}

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

        $windows = $this->shopConnector->windowsToErase($this->player, $order->turn);

        if ([] !== $windows) {
            $this->commandBus->dispatch(new EraseOrders($this->player->id, $windows));
        }
    }

    public function getPendingOrder(): ?Order
    {
        $order = $this->getCurrentTurnOrder();
        $editable = $order instanceof Order
            && OrderStatus::Pending === $order->status;

        return $editable ? $order : null;
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

    public function getOrderStatusHook(): string
    {
        return $this->getCurrentTurnOrder()?->status->value ?? 'missing';
    }

    public function getOrderHint(): string
    {
        $status = $this->getCurrentTurnOrder()?->status->value ?? 'empty';

        return \sprintf(
            'Order for turn %d — %s',
            $this->player->game->currentTurn,
            'pending' === $status ? 'submitted' : $status,
        );
    }

    public function isOrderVisible(): bool
    {
        return $this->getCurrentTurnOrder() instanceof Order && !$this->isCartVisible();
    }

    public function getCartStamp(): string
    {
        return $this->cartStorage->load($this->getCartKey())->stamp();
    }

    public function getRemainingBudget(): ?int
    {
        return null === $this->budget ? null : $this->budget - $this->getCartTotal();
    }

    public function getCatalogView(): CatalogView
    {
        return CatalogView::kiosk($this->isLockedForTurn(), $this->getRemainingBudget(), $this->sort);
    }

    public function getCartKey(): string
    {
        return CartKey::shop($this->player);
    }

    private function isCartEmpty(): bool
    {
        $cart = $this->cartStorage->load($this->getCartKey());

        return $cart->isEmpty();
    }

    private function isCartVisible(): bool
    {
        return !$this->getCurrentTurnOrder() instanceof Order || !$this->isCartEmpty();
    }

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
