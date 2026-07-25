<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Game\Dto\Advance;
use App\Game\Shop\ShopConnector;
use App\Shop\Cart as CartDomain;
use App\Shop\CartRepository;
use App\Shop\Dto\OrderLine;
use App\Shop\Promotion\AppliedPromotion;
use App\Shop\Promotion\PromotionEngine;
use App\Shop\Promotion\PromotionType;
use App\Shop\Service\LineQuoter;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: 'organisms/cart.html.twig')]
final class Cart
{
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

    public function __construct(
        private readonly AdvanceCatalog $advanceCatalog,
        private readonly CartRepository $cartRepository,
        private readonly LineQuoter $lineQuoter,
        private readonly PromotionEngine $promotionEngine,
        private readonly ShopConnector $shopConnector,
    ) {}

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
    public function allocate(#[LiveArg] string $for, #[LiveArg] string $category, #[LiveArg] int $delta): void
    {
        $cart = $this->getCart();
        $cart->withAllocation($for, $category, $delta);
        $this->cartRepository->save($this->storageKey, $cart);
    }

    /** @return list<array{advance: Advance, line: OrderLine}> */
    public function getLines(): array
    {
        return $this->toRows($this->lineQuoter->quotePreview($this->getCart()->items, $this->player, $this->shopConnector->buckets()));
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

        return $this->promotionEngine->giftCandidates(
            $source,
            $this->player->advances,
            $this->getCart()->keys(),
            $this->advanceCatalog->getAdvances(),
        );
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
     * Every bucket defaulted to 0, in ShopConnector::buckets() order, so the
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

        foreach ($this->shopConnector->buckets() as $bucket) {
            $allocation[$bucket] = $stored[$bucket] ?? 0;
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
