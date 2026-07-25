<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Order;
use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Game\Dto\Advance;
use App\Game\Shop\ShopConnector;
use App\Repository\OrderRepository;
use App\Shop\Cart;
use App\Shop\CartRepository;
use App\Shop\Command\SubmitOrder;
use App\Shop\Dto\OrderLine;
use App\Shop\Exception\ShopException;
use App\Shop\OrderStatus;
use App\Shop\Service\LineQuoter;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: 'organisms/shop.html.twig')]
final class Shop
{
    use DefaultActionTrait;
    use HasIncompleteAllocationsTrait;

    #[LiveProp]
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public ?string $error = null;
    private bool $currentOrderLoaded = false;
    private ?Order $currentOrder = null;

    public function __construct(
        private readonly AdvanceCatalog $advanceCatalog,
        private readonly CartRepository $cartRepository,
        private readonly OrderRepository $orderRepository,
        private readonly LineQuoter $lineQuoter,
        private readonly MessageBusInterface $commandBus,
        private readonly ShopConnector $shopConnector,
    ) {}

    #[LiveAction]
    public function clearCart(): void
    {
        $this->cartRepository->clear((string) $this->player->id);
    }

    #[LiveAction]
    public function add(#[LiveArg] string $key): void
    {
        if ($this->isLockedForTurn()) {
            $this->error = 'An order has already been validated for this turn.';

            return;
        }

        if (\in_array($key, $this->player->advances, true)) {
            $this->error = sprintf('Advance "%s" is already owned.', $key);

            return;
        }

        $cart = $this->cartRepository->findOrCreate((string) $this->player->id);

        if ($cart->has($key)) {
            return;
        }

        $cart->add($key);
        $this->cartRepository->save((string) $this->player->id, $cart);
        $this->error = null;
    }

    #[LiveAction]
    public function submitOrder(): void
    {
        try {
            $cart = $this->cartRepository->findOrCreate((string) $this->player->id);
            $window = $this->shopConnector->currentWindow($this->player->game);
            $this->commandBus->dispatch(new SubmitOrder($this->player->id, $cart->items, $window));
            $this->cartRepository->clear((string) $this->player->id);
            $this->error = null;
        } catch (HandlerFailedException $exception) {
            foreach ($exception->getWrappedExceptions() as $wrapped) {
                if ($wrapped instanceof ShopException) {
                    $this->error = $wrapped->getMessage();

                    return;
                }
            }

            throw $exception;
        }
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

        $this->cartRepository->save((string) $this->player->id, $cart);
    }

    /** Editable order for the current turn — a rejected order reopens for revision, resubmitting it. */
    public function getPendingOrder(): ?Order
    {
        $order = $this->getCurrentTurnOrder();
        $editable = $order instanceof Order
            && (OrderStatus::Pending === $order->status || OrderStatus::Rejected === $order->status);

        return $editable ? $order : null;
    }

    /** Whether any option-promoted cart line still has an unspent balance — gates the submit button. */
    public function hasIncompleteAllocations(): bool
    {
        $cart = $this->cartRepository->findOrCreate((string) $this->player->id);

        return $this->isCartHasIncompleteAllocations($cart, $this->advanceCatalog);
    }

    public function isCartEmpty(): bool
    {
        $cart = $this->cartRepository->findOrCreate((string) $this->player->id);

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
            : $this->lineQuoter->quote($this->lineQuoter->intentsFromLines($order->lines()), $this->player, $this->shopConnector->buckets());

        return $this->toRows($lines);
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

    /** Cart is shown when there is no order for this turn, or while editing one (non-empty cart). */
    public function isCartVisible(): bool
    {
        return !$this->getCurrentTurnOrder() instanceof Order || !$this->isCartEmpty();
    }

    /** Order block is shown whenever an order exists and it isn't currently being edited in the cart. */
    public function isOrderVisible(): bool
    {
        return $this->getCurrentTurnOrder() instanceof Order && !$this->isCartVisible();
    }

    public function getCartStamp(): string
    {
        return $this->cartRepository->findOrCreate((string) $this->player->id)->stamp();
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

    /**
     * @param list<OrderLine> $lines
     *
     * @return list<array{advance: Advance, line: OrderLine}>
     */
    private function toRows(array $lines): array
    {
        return array_map(
            function (OrderLine $line): ?array {
                $advance = $this->advanceCatalog->getAdvanceByName($line->key);

                return $advance instanceof Advance ? ['advance' => $advance, 'line' => $line] : null;
            },
            $lines,
        )
                |> array_filter(...)
                |> array_values(...);
    }

    /** @param list<array{advance: Advance, line: OrderLine}> $rows */
    private function sumNetCost(array $rows): int
    {
        return array_sum(array_map(static fn (array $row): int => $row['line']->netCost, $rows));
    }
}
