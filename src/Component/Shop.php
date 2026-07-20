<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Order;
use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Game\Dto\Advance;
use App\Repository\OrderRepository;
use App\Shop\Cart;
use App\Shop\CartRepository;
use App\Shop\Dto\Product;
use App\Shop\OrderStatus;
use App\Shop\Service\OrderSubmitter;
use App\Shop\Service\PriceCalculator;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: 'organisms/shop.html.twig')]
final class Shop
{
    use DefaultActionTrait;

    #[LiveProp]
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public ?string $error = null;

    /** @var ?list<Product> */
    private ?array $products = null;

    private bool $currentOrderLoaded = false;
    private ?Order $currentOrder = null;

    public function __construct(
        private readonly AdvanceCatalog $advanceCatalog,
        private readonly PriceCalculator $priceCalculator,
        private readonly CartRepository $cartRepository,
        private readonly OrderRepository $orderRepository,
        private readonly OrderSubmitter $orderSubmitter,
    ) {}

    #[LiveAction]
    public function addToCart(#[LiveArg] string $key): void
    {
        if ($this->isLockedForTurn()) {
            $this->error = 'An order has already been validated for this turn.';

            return;
        }

        if (\in_array($key, $this->player->advances, true)) {
            $this->error = sprintf('Advance "%s" is already owned.', $key);

            return;
        }

        $cart = $this->getCart();

        if ($cart->has($key)) {
            return;
        }

        $cart->add($key);
        $this->cartRepository->save($this->player->id, $cart);
        $this->error = null;
    }

    #[LiveAction]
    public function removeFromCart(#[LiveArg] string $key): void
    {
        $cart = $this->getCart();
        $cart->remove($key);
        $this->cartRepository->save($this->player->id, $cart);
    }

    #[LiveAction]
    public function clearCart(): void
    {
        $this->cartRepository->clear($this->player->id);
    }

    #[LiveAction]
    public function submitOrder(): void
    {
        try {
            $this->orderSubmitter->submit($this->player);
            $this->error = null;
        } catch (\DomainException $exception) {
            $this->error = $exception->getMessage();
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

        /** @var list<string> $keys */
        $keys = $order->lines;

        foreach ($keys as $key) {
            $cart->add($key);
        }

        $this->cartRepository->save($this->player->id, $cart);
    }

    /** @return list<Product> */
    public function getProducts(): array
    {
        if (null !== $this->products) {
            return $this->products;
        }

        /** @var list<Advance> $ownedAdvances */
        $ownedAdvances = $this->advanceCatalog->getAdvancesByNames($this->player->advances);
        $cart = $this->getCart();

        $this->products = array_values(array_filter(array_map(
            fn (Advance $advance): ?Product => \in_array($advance->key, $this->player->advances, true)
                ? null
                : new Product(
                    advance: $advance,
                    netCost: $this->priceCalculator->netCost($advance, $ownedAdvances),
                    owned: false,
                    inCart: $cart->has($advance->key),
                ),
            $this->advanceCatalog->getAdvances(),
        )));

        return $this->products;
    }

    /** @return list<Product> */
    public function getCartLines(): array
    {
        return Product::filterByKeys($this->getProducts(), $this->getCart()->items);
    }

    public function getCartTotal(): int
    {
        return array_sum(array_map(
            static fn (Product $product): int => $product->netCost,
            $this->getCartLines(),
        ));
    }

    public function getPendingOrder(): ?Order
    {
        $order = $this->getCurrentTurnOrder();

        return ($order instanceof Order && OrderStatus::Pending === $order->status) ? $order : null;
    }

    /** @return list<Product> */
    public function getOrderLines(): array
    {
        $order = $this->getCurrentTurnOrder();

        if (!$order instanceof Order) {
            return [];
        }

        if (OrderStatus::Validated === $order->status) {
            return $this->getValidatedOrderLines($order);
        }

        /** @var list<string> $keys */
        $keys = $order->lines;

        return Product::filterByKeys($this->getProducts(), $keys);
    }

    public function getOrderTotal(): int
    {
        $order = $this->getCurrentTurnOrder();

        if ($order instanceof Order && OrderStatus::Validated === $order->status) {
            return $order->total ?? 0;
        }

        return array_sum(array_map(
            static fn (Product $product): int => $product->netCost,
            $this->getOrderLines(),
        ));
    }

    public function isLockedForTurn(): bool
    {
        return OrderStatus::Validated === $this->getCurrentTurnOrder()?->status;
    }

    /** Cart is shown when there is no order for this turn, or while editing one (non-empty cart). */
    public function isCartVisible(): bool
    {
        return !$this->getCurrentTurnOrder() instanceof Order || [] !== $this->getCartLines();
    }

    /** Order block is shown whenever an order exists and it isn't currently being edited in the cart. */
    public function isOrderVisible(): bool
    {
        return $this->getCurrentTurnOrder() instanceof Order && !$this->isCartVisible();
    }

    private function getCurrentTurnOrder(): ?Order
    {
        if (!$this->currentOrderLoaded) {
            $this->currentOrder = $this->orderRepository->findOneByPlayerAndTurn(
                $this->player,
                $this->player->game->currentTurn,
            );
            $this->currentOrderLoaded = true;
        }

        return $this->currentOrder;
    }

    private function getCart(): Cart
    {
        return $this->cartRepository->findOrCreate($this->player->id);
    }

    /** @return list<Product> */
    private function getValidatedOrderLines(Order $order): array
    {
        /** @var list<array{key: string, netCost: int}> $frozenLines */
        $frozenLines = $order->lines;

        return array_values(array_filter(array_map(
            function (array $line): ?Product {
                $advance = $this->advanceCatalog->getAdvanceByName($line['key']);

                return $advance instanceof Advance
                    ? new Product(advance: $advance, netCost: $line['netCost'], owned: true, inCart: false)
                    : null;
            },
            $frozenLines,
        )));
    }
}
