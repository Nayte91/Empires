<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Order;
use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Game\Dto\Advance;
use App\Repository\OrderRepository;
use App\Shop\Dto\Product;
use App\Shop\OrderStatus;
use App\Shop\Service\DirectSale;
use App\Shop\Service\OrderEraser;
use App\Shop\Service\PriceCalculator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: 'organisms/playerOrders.html.twig')]
final class PlayerOrders
{
    use DefaultActionTrait;

    #[LiveProp]
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    /** Fingerprint only: any change remounts this component fresh (see StatPicker's :value for the same mechanism). */
    #[LiveProp]
    public string $ordersStamp; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp]
    public bool $posOpen = false;

    #[LiveProp]
    public int $posTurn = 0;

    /** @var list<string> */
    #[LiveProp]
    public array $ticket = [];

    public ?string $error = null;

    /** @var ?list<Product> */
    private ?array $products = null;

    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly AdvanceCatalog $advanceCatalog,
        private readonly PriceCalculator $priceCalculator,
        private readonly DirectSale $directSale,
        private readonly OrderEraser $orderEraser,
    ) {}

    #[LiveAction]
    public function openPos(#[LiveArg] int $turn): void
    {
        $this->posTurn = $turn;
        $this->error = null;
        $this->posOpen = true;

        $order = $this->orderRepository->findOneByPlayerAndTurn($this->player, $turn);

        if (!$order instanceof Order || OrderStatus::Pending !== $order->status) {
            $this->ticket = [];

            return;
        }

        /** @var list<string> $lines */
        $lines = $order->lines;
        $this->ticket = $lines;
    }

    #[LiveAction]
    public function closePos(): void
    {
        $this->posOpen = false;
    }

    #[LiveAction]
    public function eraseOrder(#[LiveArg] int $turn): void
    {
        $order = $this->orderRepository->findOneByPlayerAndTurn($this->player, $turn);

        if (!$order instanceof Order) {
            return;
        }

        $this->orderEraser->erase($order);
    }

    #[LiveAction]
    public function addToTicket(#[LiveArg] string $key): void
    {
        if (\in_array($key, $this->player->advances, true)) {
            $this->error = sprintf('Advance "%s" is already owned.', $key);

            return;
        }

        if (\in_array($key, $this->ticket, true)) {
            return;
        }

        $this->ticket[] = $key;
        $this->error = null;
    }

    #[LiveAction]
    public function removeFromTicket(#[LiveArg] string $key): void
    {
        $this->ticket = array_values(array_filter(
            $this->ticket,
            static fn (string $existing): bool => $existing !== $key,
        ));
    }

    #[LiveAction]
    public function checkout(): void
    {
        try {
            $this->directSale->sell($this->player, $this->ticket, $this->posTurn);

            $this->ticket = [];
            $this->error = null;
        } catch (\DomainException $exception) {
            $this->error = $exception->getMessage();
        } catch (UniqueConstraintViolationException) {
            $this->error = 'Order already submitted for this turn, please retry.';
        }
    }

    /** The order (if any) for the turn currently open in the POS. */
    public function getPosOrder(): ?Order
    {
        return $this->orderRepository->findOneByPlayerAndTurn($this->player, $this->posTurn);
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

        /** @var list<Advance> $ownedAdvances */
        $ownedAdvances = $this->advanceCatalog->getAdvancesByNames($this->player->advances);

        $cards = [];

        for ($turn = $this->player->game->currentTurn; $turn >= 1; --$turn) {
            $cards[] = $this->summarizeTurn($turn, $byTurn[$turn] ?? null, $ownedAdvances);
        }

        return $cards;
    }

    /**
     * Catalogue minus the player's already-owned advances — a cashier has no
     * use for re-selling what a player already has.
     *
     * REFACTOR-WHEN: a 3rd component builds this Product list (Shop::getProducts()
     * is the near-identical sibling, only the in-cart source differs) — extract a
     * Shop\Service\ProductCatalog instead of copying it again.
     *
     * @return list<Product>
     */
    public function getProducts(): array
    {
        if (null !== $this->products) {
            return $this->products;
        }

        /** @var list<Advance> $ownedAdvances */
        $ownedAdvances = $this->advanceCatalog->getAdvancesByNames($this->player->advances);

        $this->products = array_values(array_filter(array_map(
            fn (Advance $advance): ?Product => \in_array($advance->key, $this->player->advances, true)
                ? null
                : new Product(
                    advance: $advance,
                    netCost: $this->priceCalculator->netCost($advance, $ownedAdvances),
                    owned: false,
                    inCart: \in_array($advance->key, $this->ticket, true),
                ),
            $this->advanceCatalog->getAdvances(),
        )));

        return $this->products;
    }

    /** @return list<Product> */
    public function getTicketLines(): array
    {
        return Product::filterByKeys($this->getProducts(), $this->ticket);
    }

    public function getTicketTotal(): int
    {
        return array_sum(array_map(
            static fn (Product $product): int => $product->netCost,
            $this->getTicketLines(),
        ));
    }

    /**
     * Totals are frozen on the order once validated, otherwise recalculated
     * against the player's currently owned advances.
     *
     * @param list<Advance> $ownedAdvances
     *
     * @return array{turn: int, status: string, slugs: list<string>, total: int, vp: int}
     */
    private function summarizeTurn(int $turn, ?Order $order, array $ownedAdvances): array
    {
        /** @var list<string> $slugs */
        $slugs = match (true) {
            !$order instanceof Order => [],
            OrderStatus::Validated === $order->status => array_column($order->lines, 'key'),
            default => $order->lines,
        };

        /** @var list<Advance> $advances */
        $advances = $this->advanceCatalog->getAdvancesByNames($slugs);

        return [
            'turn' => $turn,
            'status' => $order?->status->value ?? 'empty',
            'slugs' => $slugs,
            'total' => OrderStatus::Validated === $order?->status
                ? $order->total ?? 0
                : $this->priceCalculator->orderTotal($advances, $ownedAdvances),
            'vp' => array_sum(array_map(static fn (Advance $advance): int => $advance->points, $advances)),
        ];
    }
}
