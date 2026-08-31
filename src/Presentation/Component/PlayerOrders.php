<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Presentation\Shop\CartItemAdder;
use App\Presentation\Shop\CartKey;
use App\Presentation\Shop\CatalogView;
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
use Userforged\ShopEngine\BuyerInterface;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Command\EraseOrders;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Service\LineQuoter;

#[AsLiveComponent(template: 'organisms/PlayerOrders.html.twig')]
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
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AdvanceRegistry $advanceRegistry,
        private readonly CartItemAdder $cartItemAdder,
        private readonly CartStorageInterface $cartStorage,
        private readonly LineQuoter $lineQuoter,
        private readonly MessageBusInterface $commandBus,
        private readonly ShopConnector $shopConnector,
    ) {}

    #[LiveAction]
    public function add(#[LiveArg] string $key): void
    {
        $this->error = $this->cartItemAdder->add($this->player, $this->getPosCartKey(), $key);
    }

    #[LiveAction]
    public function openPos(#[LiveArg] int $turn): void
    {
        $this->posTurn = $turn;
        $this->error = null;
        $this->posOpen = true;

        $order = $this->orderRepository->findOneByPlayerAndWindow($this->player, $turn);

        if (!$order instanceof Order || OrderStatus::Pending !== $order->status) {
            $this->cartStorage->clear($this->getPosCartKey());

            return;
        }

        $cart = new Cart();
        $cart->items = $this->lineQuoter->intentsFromLines($order->lines());

        $this->cartStorage->save($this->getPosCartKey(), $cart);
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

    /** Re-renders PlayerOrders so its order cards reflect the order Cart::checkout() just placed. */
    #[LiveListener('orderPlaced')]
    public function onOrderPlaced(): void {}

    /** Re-renders the POS catalogue so it puts back what Cart::remove() or Cart::clear() released. */
    #[LiveListener('cartChanged')]
    public function onCartChanged(): void {}

    public function getPosOrder(): ?Order
    {
        return $this->orderRepository->findOneByPlayerAndWindow($this->player, $this->posTurn);
    }

    public function getCartStamp(): string
    {
        return $this->cartStorage->load($this->getPosCartKey())->stamp();
    }

    /** Public for organisms/posDialog, which hands it to the nested Catalog and Cart as their storageKey. */
    public function getPosCartKey(): string
    {
        return CartKey::pos($this->player);
    }

    public function getCatalogView(): CatalogView
    {
        return CatalogView::pos();
    }

    /**
     * One card per turn, current turn first, whether an order exists for it or
     * not — a kiosk-submitted pending order fills its turn's card.
     *
     * @return list<array{turn: int, status: string, slugs: list<string>, total: int, vp: int}>
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
        // that validates or erases an order and then re-renders in the same
        // request needs a freshly-built buyer, or it self-credits stale data.
        $buyer = $this->shopConnector->buyerFor($this->player);

        $cards = [];

        for ($turn = $this->player->game->currentTurn; $turn >= 1; --$turn) {
            $cards[] = $this->summarizeTurn($turn, $byTurn[$turn] ?? null, $buyer);
        }

        return $cards;
    }

    /** @return array{turn: int, status: string, slugs: list<string>, total: int, vp: int} */
    private function summarizeTurn(int $turn, ?Order $order, BuyerInterface $buyer): array
    {
        $slugs = $order?->keys() ?? [];

        /** @var list<Advance> $advances */
        $advances = $this->advanceRegistry->getAdvancesByNames($slugs);

        $total = OrderStatus::Validated === $order?->status
            ? $order->total ?? 0
            : array_sum(array_map(
                static fn (OrderLine $line): int => $line->netCost,
                $this->lineQuoter->quote($order instanceof Order ? $this->lineQuoter->intentsFromLines($order->lines()) : [], $buyer),
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
        ];
    }
}
