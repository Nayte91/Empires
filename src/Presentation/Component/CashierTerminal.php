<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Presentation\Shop\CartItemAdder;
use App\Presentation\Shop\CartKey;
use App\Presentation\Shop\CatalogView;
use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Shop\ShopConnector;
use App\State\Game;
use App\State\Order;
use App\State\Player;
use App\State\Repository\OrderRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Command\EraseOrders;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Service\LineQuoter;

/**
 * REFACTOR-WHEN: a 3rd host of Cart/Catalog appears — `add()`, `getCartStamp()`, `getCartKey()`
 * and `getCatalogView()` are then worth a shared trait. At two hosts they are four one-line
 * delegations to services both already inject, and a base class would cost more than it returns.
 */
#[AsLiveComponent(template: 'organisms/CashierTerminal.html.twig')]
final class CashierTerminal
{
    use DefaultActionTrait;
    use OrderRowsTrait;

    #[LiveProp]
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp(writable: true, onUpdated: 'preloadTicket', url: true)]
    public int $turn = 0;

    #[LiveProp(writable: true, onUpdated: 'preloadTicket', url: new UrlMapping(as: 'player'))]
    public ?string $playerSlug = null;

    public ?string $error = null;

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CartItemAdder $cartItemAdder,
        private readonly CartStorageInterface $cartStorage,
        private readonly LineQuoter $lineQuoter,
        private readonly RequestStack $requestStack,
        private readonly AdvanceRegistry $advanceRegistry,
        private readonly ShopConnector $shopConnector,
        private readonly MessageBusInterface $commandBus,
    ) {}

    public function mount(Game $game, ?int $turn = null): void
    {
        $this->game = $game;
        $this->turn = $turn ?? $game->currentTurn;
    }

    #[LiveAction]
    public function add(#[LiveArg] string $key): void
    {
        $player = $this->getPlayer();

        if ($player instanceof Player) {
            $this->error = $this->cartItemAdder->add($player, $this->getCartKey(), $key);
        }
    }

    // REFACTOR-WHEN: a 3rd caller needs it — these three lines are PlayerOrders::eraseOrder() again,
    // same ShopConnector::windowsToErase and same EraseOrders command. Two call sites of a
    // three-line dispatch do not earn a service; a third one would.
    #[LiveAction]
    public function eraseOrder(): void
    {
        $player = $this->getPlayer();

        if (!$player instanceof Player) {
            return;
        }

        $windows = $this->shopConnector->windowsToErase($player, $this->turn);

        if ([] !== $windows) {
            $this->commandBus->dispatch(new EraseOrders($player->id, $windows));
        }
    }

    /** @return list<array{advance: Advance, line: OrderLine}> */
    public function getTicketLines(): array
    {
        $order = $this->getPosOrder();

        return $order instanceof Order ? $this->toRows($order->lines(), $this->advanceRegistry) : [];
    }

    public function getTicketTotal(): int
    {
        $order = $this->getPosOrder();

        return $order instanceof Order ? $order->total ?? 0 : 0;
    }

    #[LiveListener('orderPlaced')]
    public function onOrderPlaced(): void {}

    #[LiveListener('cartChanged')]
    public function onCartChanged(): void {}

    public function getPlayer(): ?Player
    {
        foreach ($this->game->players as $player) {
            if ($player->slug === $this->playerSlug) {
                return $player;
            }
        }

        return null;
    }

    public function getPosOrder(): ?Order
    {
        $player = $this->getPlayer();

        return $player instanceof Player ? $this->orderRepository->findOneByPlayerAndWindow($player, $this->turn) : null;
    }

    public function getCartStamp(): string
    {
        return $this->cartStorage->load($this->getCartKey())->stamp();
    }

    public function getCartKey(): string
    {
        $player = $this->getPlayer();

        return $player instanceof Player ? CartKey::pos($player, $this->turn) : '';
    }

    public function getCatalogView(): CatalogView
    {
        return CatalogView::pos();
    }

    #[PostMount]
    public function preloadTicket(): void
    {
        if (!$this->requestStack->getMainRequest() instanceof Request) {
            return;
        }

        $player = $this->getPlayer();
        if (!$player instanceof Player || [] !== $this->cartStorage->load($this->getCartKey())->items) {
            return;
        }
        $order = $this->orderRepository->findOneByPlayerAndWindow($player, $this->turn);
        if (!$order instanceof Order || OrderStatus::Pending !== $order->status) {
            return;
        }
        $cart = new Cart();
        $cart->items = $this->lineQuoter->intentsFromLines($order->lines());
        $this->cartStorage->save($this->getCartKey(), $cart);
    }
}
