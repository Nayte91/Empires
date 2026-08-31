<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Presentation\Shop\ShopExceptionTranslator;
use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Shop\ShopConnector;
use App\State\Player;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Userforged\ShopEngine\Cart as CartDomain;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Command\SellDirect;
use Userforged\ShopEngine\Command\SubmitOrder;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\Promotion\AppliedPromotion;
use Userforged\ShopEngine\Promotion\PromotionEngine;
use Userforged\ShopEngine\Promotion\PromotionType;
use Userforged\ShopEngine\Service\LineQuoter;

#[AsLiveComponent(template: 'organisms/Cart.html.twig')]
final class Cart
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use HasIncompleteAllocationsTrait;
    use OrderRowsTrait;

    #[LiveProp]
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp(updateFromParent: true)]
    public bool $showLines = true;

    #[LiveProp(updateFromParent: true)]
    public string $cartStamp = '';

    #[LiveProp]
    public string $storageKey; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp(updateFromParent: true)]
    public string $checkoutLabel = 'Submit my order';

    /** Whether checkout dispatches SellDirect (POS, immediate validation) instead of SubmitOrder (player shop, pending order). */
    #[LiveProp(updateFromParent: true)]
    public bool $directSale = false;

    /** Opaque ordering-window index for the POS, where it may differ from the current turn; null lets checkout() default to the current one. */
    #[LiveProp(updateFromParent: true)]
    public ?int $window = null;

    /** Mirrors Catalog's own `locked` prop — gates checkout when the turn's order is already validated (e.g. stray shop-cart items surviving a POS-side validation of the same turn). */
    #[LiveProp(updateFromParent: true)]
    public bool $locked = false;

    /** Whether the footer offers to empty the cart. The POS does not: its operator erases a whole order instead. */
    #[LiveProp(updateFromParent: true)]
    public bool $clearable = false;

    public ?string $error = null;

    public function __construct(
        private readonly AdvanceRegistry $advanceRegistry,
        private readonly CartStorageInterface $cartStorage,
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
            $this->cartStorage->clear($this->storageKey);
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

    /**
     * Every action below changes which keys the cart holds, and the catalogue is rendered by the
     * host, not here — so the host has to be told, or an advance the cart released never reappears
     * on the shelf. Adding needs no such signal: the add action already belongs to the host, and
     * `allocate()` moves points within a line without touching the key list.
     *
     * The gift pair counts, which is easy to miss: `Cart::withGift()` appends a LineIntent for a
     * newly chosen gift and drops the old one once nothing references it, so both directions move
     * the key list exactly as remove() does.
     */
    #[LiveAction]
    public function remove(#[LiveArg] string $key): void
    {
        $cart = $this->getCart();
        $cart->remove($key);
        $this->cartStorage->save($this->storageKey, $cart);
        $this->emitUp('cartChanged');
    }

    #[LiveAction]
    public function clear(): void
    {
        $this->cartStorage->clear($this->storageKey);
        $this->emitUp('cartChanged');
    }

    #[LiveAction]
    public function chooseGift(#[LiveArg] string $for, #[LiveArg] string $key): void
    {
        $cart = $this->getCart();
        $cart->withGift($for, $key);
        $this->cartStorage->save($this->storageKey, $cart);
        $this->emitUp('cartChanged');
    }

    #[LiveAction]
    public function revokeGift(#[LiveArg] string $for): void
    {
        $cart = $this->getCart();
        $cart->withGift($for, null);
        $this->cartStorage->save($this->storageKey, $cart);
        $this->emitUp('cartChanged');
    }

    #[LiveAction]
    public function allocate(#[LiveArg] string $for, #[LiveArg] string $facet, #[LiveArg] int $delta): void
    {
        $cart = $this->getCart();
        $cart->withAllocation($for, $facet, $delta);
        $this->cartStorage->save($this->storageKey, $cart);
    }

    /** @return list<array{advance: Advance, line: OrderLine}> */
    public function getLines(): array
    {
        $buyer = $this->shopConnector->buyerFor($this->player);

        return $this->toRows($this->lineQuoter->quotePreview($this->getCart()->items, $buyer), $this->advanceRegistry);
    }

    public function getTotal(): int
    {
        return $this->sumNetCost($this->getLines());
    }

    /** @return list<Advance> */
    public function getGiftCandidates(string $for): array
    {
        $source = $this->advanceRegistry->getAdvanceByName($for);

        if (!$source instanceof Advance) {
            return [];
        }

        $candidateKeys = $this->promotionEngine->giftCandidates(
            $source,
            $this->player->advances,
            $this->getCart()->keys(),
            $this->advanceRegistry->getAdvances(),
        );

        return array_values($this->advanceRegistry->getAdvancesByNames($candidateKeys));
    }

    public function getChosenGiftFor(string $sourceKey): ?Advance
    {
        foreach ($this->getLines() as $row) {
            $line = $row['line'];

            if ($line->promotion instanceof AppliedPromotion
                && PromotionType::Gift === $line->promotion->type
                && $line->promotion->source === $sourceKey
            ) {
                return $this->advanceRegistry->getAdvanceByName($line->key);
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
        $target = $this->advanceRegistry->getAdvanceByName($key)?->promotion->option->budget ?? 0;

        return max(0, $target - array_sum($this->getAllocationFor($key)));
    }

    public function hasIncompleteAllocations(): bool
    {
        return $this->isCartHasIncompleteAllocations($this->getCart(), $this->advanceRegistry);
    }

    public function isEmpty(): bool
    {
        return $this->getCart()->isEmpty();
    }

    private function getCart(): CartDomain
    {
        return $this->cartStorage->load($this->storageKey);
    }
}
