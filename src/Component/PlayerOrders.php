<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Order;
use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Game\Dto\Advance;
use App\Game\Shop\ShopConnector;
use App\Repository\OrderRepository;
use App\Shop\BuyerInterface;
use App\Shop\Cart;
use App\Shop\CartRepository;
use App\Shop\Command\EraseOrders;
use App\Shop\Command\RejectOrder;
use App\Shop\Dto\OrderLine;
use App\Shop\Exception\ShopException;
use App\Shop\OrderStatus;
use App\Shop\Service\LineQuoter;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: 'organisms/playerOrders.html.twig')]
final class PlayerOrders
{
    use DefaultActionTrait;

    #[LiveProp]
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp(updateFromParent: true)]
    public string $ordersStamp; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp]
    public bool $posOpen = false;

    #[LiveProp]
    public int $posTurn = 0;

    public ?string $error = null;

    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly AdvanceCatalog $advanceCatalog,
        private readonly CartRepository $cartRepository,
        private readonly LineQuoter $lineQuoter,
        private readonly MessageBusInterface $commandBus,
        private readonly ShopConnector $shopConnector,
        private readonly WorkflowInterface $shopOrderStateMachine,
    ) {}

    #[LiveAction]
    public function add(#[LiveArg] string $key): void
    {
        if (\in_array($key, $this->player->advances, true)) {
            $this->error = sprintf('Advance "%s" is already owned.', $key);

            return;
        }

        $cart = $this->cartRepository->findOrCreate($this->posCartKey());

        if ($cart->has($key)) {
            return;
        }

        $cart->add($key);
        $this->cartRepository->save($this->posCartKey(), $cart);
        $this->error = null;
    }

    #[LiveAction]
    public function openPos(#[LiveArg] int $turn): void
    {
        $this->posTurn = $turn;
        $this->error = null;
        $this->posOpen = true;

        $order = $this->orderRepository->findOneByPlayerAndWindow($this->player, $turn);

        if (!$order instanceof Order || OrderStatus::Pending !== $order->status) {
            $this->cartRepository->clear($this->posCartKey());

            return;
        }

        $cart = new Cart();
        $cart->items = $this->lineQuoter->intentsFromLines($order->lines());

        $this->cartRepository->save($this->posCartKey(), $cart);
    }

    #[LiveAction]
    public function closePos(): void
    {
        $this->posOpen = false;
    }

    #[LiveAction]
    public function eraseOrder(#[LiveArg] int $turn): void
    {
        $windows = $this->shopConnector->windowsToErase($this->player, $turn);

        if ([] !== $windows) {
            $this->commandBus->dispatch(new EraseOrders($this->player->id, $windows));
        }
    }

    #[LiveAction]
    public function rejectOrder(#[LiveArg] int $turn): void
    {
        try {
            $this->commandBus->dispatch(new RejectOrder($this->player->id, $turn));
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

    /** Re-renders PlayerOrders so its order cards reflect the order Cart::checkout() just placed. */
    #[LiveListener('orderPlaced')]
    public function onOrderPlaced(): void {}

    public function isTicketEmpty(): bool
    {
        $cart = $this->cartRepository->findOrCreate($this->posCartKey());

        return $cart->isEmpty();
    }

    /** The order (if any) for the turn currently open in the POS. */
    public function getPosOrder(): ?Order
    {
        return $this->orderRepository->findOneByPlayerAndWindow($this->player, $this->posTurn);
    }

    public function getCartStamp(): string
    {
        return $this->cartRepository->findOrCreate($this->posCartKey())->stamp();
    }

    /**
     * One card per turn, current turn first, whether an order exists for it or
     * not — a kiosk-submitted pending order fills its turn's card.
     *
     * @return list<array{turn: int, status: string, slugs: list<string>, total: int, vp: int, rejectable: bool}>
     */
    public function getCards(): array
    {
        $byTurn = [];

        foreach ($this->orderRepository->findByPlayer($this->player) as $order) {
            $byTurn[$order->turn] = $order;
        }

        // Built once for this render pass, not per turn: buyerFor() runs a query,
        // and this loop would otherwise re-run it once per turn card (N+1). Safe
        // to reuse here because the loop is read-only — nothing between turns
        // mutates orders/advances. This must NOT be hoisted any further up
        // (e.g. into a service-level cache keyed by player id): any LiveAction
        // that validates/erases/rejects an order and then re-renders in the same
        // request needs a freshly-built buyer, or it self-credits stale data.
        $buyer = $this->shopConnector->buyerFor($this->player);

        $cards = [];

        for ($turn = $this->player->game->currentTurn; $turn >= 1; --$turn) {
            $cards[] = $this->summarizeTurn($turn, $byTurn[$turn] ?? null, $buyer);
        }

        return $cards;
    }

    /**
     * Totals are frozen on the order once validated, otherwise recalculated
     * against the buyer's currently owned advances.
     *
     * @return array{turn: int, status: string, slugs: list<string>, total: int, vp: int, rejectable: bool}
     */
    private function summarizeTurn(int $turn, ?Order $order, BuyerInterface $buyer): array
    {
        $slugs = $order?->keys() ?? [];

        /** @var list<Advance> $advances */
        $advances = $this->advanceCatalog->getAdvancesByNames($slugs);

        $total = OrderStatus::Validated === $order?->status
            ? $order->total ?? 0
            : array_sum(array_map(
                static fn (OrderLine $line): int => $line->netCost,
                $this->lineQuoter->quote($order instanceof Order ? $this->lineQuoter->intentsFromLines($order->lines()) : [], $buyer, $this->shopConnector->facets()),
            ));

        return [
            'turn' => $turn,
            'status' => match (true) {
                $order instanceof Order => $order->status->value,
                $turn === $this->player->game->currentTurn => 'missing',
                default => 'empty',
            },
            'slugs' => $slugs,
            'total' => $total,
            'vp' => array_sum(array_map(static fn (Advance $advance): int => $advance->points, $advances)),
            'rejectable' => $order instanceof Order && $this->shopOrderStateMachine->can($order, 'reject'),
        ];
    }

    private function posCartKey(): string
    {
        return 'pos.'.$this->player->id->toRfc4122();
    }
}
