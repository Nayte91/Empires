<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Game\Dto\Advance;
use App\Game\Shop\ShopConnector;
use App\Game\Shop\ShopExceptionTranslator;
use App\Shop\Cart as CartDomain;
use App\Shop\CartRepository;
use App\Shop\Command\SellDirect;
use App\Shop\Command\SubmitOrder;
use App\Shop\Dto\OrderLine;
use App\Shop\Promotion\AppliedPromotion;
use App\Shop\Promotion\PromotionEngine;
use App\Shop\Promotion\PromotionType;
use App\Shop\Service\LineQuoter;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: 'organisms/cart.html.twig')]
final class Cart
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use HasIncompleteAllocationsTrait;

    #[LiveProp]
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp(updateFromParent: true)]
    public bool $showLines = true;

    #[LiveProp(updateFromParent: true)]
    public string $cartStamp = '';

    #[LiveProp]
    public string $storageKey; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    /** Button label — "Submit my order" for the player shop, "Confirm purchase" for the operator POS. */
    #[LiveProp(updateFromParent: true)]
    public string $checkoutLabel = 'Submit my order';

    /** Whether checkout dispatches SellDirect (POS, immediate validation) instead of SubmitOrder (player shop, pending order). */
    #[LiveProp(updateFromParent: true)]
    public bool $directSale = false;

    /** Opaque ordering-window index for the POS, where it may differ from the current turn; null lets checkout() default to the current one. */
    #[LiveProp(updateFromParent: true)]
    public ?int $window = null;

    /** Mirrors ProductGrid's own `locked` prop — gates checkout when the turn's order is already validated (e.g. stray shop-cart items surviving a POS-side validation of the same turn). */
    #[LiveProp(updateFromParent: true)]
    public bool $locked = false;

    public ?string $error = null;

    public function __construct(
        private readonly AdvanceCatalog $advanceCatalog,
        private readonly CartRepository $cartRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly LineQuoter $lineQuoter,
        private readonly PromotionEngine $promotionEngine,
        private readonly ShopConnector $shopConnector,
        private readonly ShopExceptionTranslator $shopExceptionTranslator,
    ) {}

    #[LiveAction]
    public function checkout(): void
    {
        try {
            $cart = $this->getCart();
            $window = $this->window ?? $this->shopConnector->currentWindow($this->player->game);
            $command = $this->directSale
                ? new SellDirect($this->player->id, $cart->items, $window)
                : new SubmitOrder($this->player->id, $cart->items, $window);

            $this->commandBus->dispatch($command);
            $this->cartRepository->clear($this->storageKey);
            $this->error = null;
            $this->emitUp('orderPlaced');
        } catch (HandlerFailedException $exception) {
            $message = $this->shopExceptionTranslator->messageFor($exception);

            if (null === $message) {
                throw $exception;
            }

            $this->error = $message;
        }
    }

    #[LiveAction]
    public function remove(#[LiveArg] string $key): void
    {
        $cart = $this->getCart();
        $cart->remove($key);
        $this->cartRepository->save($this->storageKey, $cart);
    }

    #[LiveAction]
    public function chooseGift(#[LiveArg] string $for, #[LiveArg] string $key): void
    {
        $cart = $this->getCart();
        $cart->withGift($for, $key);
        $this->cartRepository->save($this->storageKey, $cart);
    }

    #[LiveAction]
    public function revokeGift(#[LiveArg] string $for): void
    {
        $cart = $this->getCart();
        $cart->withGift($for, null);
        $this->cartRepository->save($this->storageKey, $cart);
    }

    #[LiveAction]
    public function allocate(#[LiveArg] string $for, #[LiveArg] string $facet, #[LiveArg] int $delta): void
    {
        $cart = $this->getCart();
        $cart->withAllocation($for, $facet, $delta);
        $this->cartRepository->save($this->storageKey, $cart);
    }

    /** @return list<array{advance: Advance, line: OrderLine}> */
    public function getLines(): array
    {
        $buyer = $this->shopConnector->buyerFor($this->player);

        return $this->toRows($this->lineQuoter->quotePreview($this->getCart()->items, $buyer, $this->shopConnector->facets()));
    }

    public function getTotal(): int
    {
        return $this->sumNetCost($this->getLines());
    }

    /** @return list<Advance> */
    public function getGiftCandidates(string $for): array
    {
        $source = $this->advanceCatalog->getAdvanceByName($for);

        if (!$source instanceof Advance) {
            return [];
        }

        $candidateKeys = $this->promotionEngine->giftCandidates(
            $source,
            $this->player->advances,
            $this->getCart()->keys(),
            $this->advanceCatalog->getAdvances(),
        );

        return array_values($this->advanceCatalog->getAdvancesByNames($candidateKeys));
    }

    public function getChosenGiftFor(string $sourceKey): ?Advance
    {
        foreach ($this->getLines() as $row) {
            $line = $row['line'];

            if ($line->promotion instanceof AppliedPromotion
                && PromotionType::Gift === $line->promotion->type
                && $line->promotion->source === $sourceKey
            ) {
                return $this->advanceCatalog->getAdvanceByName($line->key);
            }
        }

        return null;
    }

    /**
     * Every facet defaulted to 0, in ShopConnector::facets() order, so the
     * picker template can iterate it directly (mirrors Component\Discounts::getCredits).
     *
     * @return array<string, int>
     */
    public function getAllocationFor(string $key): array
    {
        $stored = [];

        foreach ($this->getCart()->items as $item) {
            if ($item->key === $key) {
                $stored = $item->allocation;

                break;
            }
        }

        $allocation = [];

        foreach ($this->shopConnector->facets() as $facet) {
            $allocation[$facet] = $stored[$facet] ?? 0;
        }

        return $allocation;
    }

    public function getAllocationRemaining(string $key): int
    {
        $target = $this->advanceCatalog->getAdvanceByName($key)?->promotion->option->budget ?? 0;

        return max(0, $target - array_sum($this->getAllocationFor($key)));
    }

    public function hasIncompleteAllocations(): bool
    {
        return $this->isCartHasIncompleteAllocations($this->getCart(), $this->advanceCatalog);
    }

    public function isEmpty(): bool
    {
        return $this->getCart()->isEmpty();
    }

    private function getCart(): CartDomain
    {
        return $this->cartRepository->findOrCreate($this->storageKey);
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
