<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Presentation\Shop\CatalogSort;
use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Shop\ShopConnector;
use App\State\Player;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Dto\Product;
use Userforged\ShopEngine\Service\ProductCatalog;

#[AsTwigComponent(name: 'organisms:Catalog', template: 'organisms/catalog.html.twig')]
final class Catalog
{
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)
    public string $storageKey; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)
    public bool $locked = false;

    /**
     * Defaults to the order the catalogue has always had, so a caller that says nothing is
     * unaffected — the POS console relies on that.
     */
    public CatalogSort $sort = CatalogSort::ListPrice;

    /**
     * Raw (possibly negative) remaining budget from the kiosk shop's planning-aid band; null
     * means no constraint. Always null for the operator POS, which never sets it, so the band
     * has no effect there.
     */
    public ?int $remainingBudget = null;

    // REFACTOR-WHEN: a third presentation flag lands here. Two (locked, sort) still read as
    // independent switches a caller sets by name; past that they are a view-model in denial, and
    // callers start passing combinations nobody has checked against each other.
    // This ticket (#33) added remainingBudget as that third flag — shipped as-is per the
    // project's convention; the refactor itself is left as a follow-up, not implemented here.

    public function __construct(
        private readonly AdvanceRegistry $advanceRegistry,
        private readonly CartStorageInterface $cartStorage,
        private readonly ProductCatalog $productCatalog,
        private readonly ShopConnector $shopConnector,
    ) {}

    /** Takes the sort as its string value so a future selector can hand one straight through. */
    public function mount(string $sort = CatalogSort::ListPrice->value): void
    {
        $this->sort = CatalogSort::from($sort);
    }

    /** @return list<array{advance: Advance, product: Product}> */
    public function getRows(): array
    {
        $advancesByKey = [];

        foreach ($this->advanceRegistry->getAdvances() as $advance) {
            $advancesByKey[$advance->key] = $advance;
        }

        // A product sitting in the cart leaves the grid entirely rather than lingering disabled:
        // the cart is already showing it, and a shelf that still displays what you have picked up
        // makes the remaining choice harder to read.
        $products = array_values(array_filter(
            $this->productCatalog->productsFor(
                $this->shopConnector->buyerFor($this->player),
                $this->getCart()->keys(),
            ),
            static fn (Product $product): bool => !$product->inCart,
        ));

        $rows = array_map(
            static fn (Product $product): array => ['advance' => $advancesByKey[$product->key], 'product' => $product],
            $products,
        );

        // Stable since PHP 8.0, so equal prices keep the order they arrived in — which is
        // AdvanceRegistry's own list-price sort. No tie-breaker to invent.
        usort($rows, $this->sort->compare(...));

        return $rows;
    }

    private function getCart(): Cart
    {
        return $this->cartStorage->load($this->storageKey);
    }
}
