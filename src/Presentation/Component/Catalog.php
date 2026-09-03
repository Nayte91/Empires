<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Presentation\Shop\CatalogView;
use App\Presentation\Shop\ShelfProvider;
use App\Rules\Ruleset\Advance;
use App\State\Player;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Userforged\ShopEngine\Dto\Product;

#[AsTwigComponent(name: 'organisms:Catalog', template: 'organisms/Catalog.html.twig')]
final class Catalog
{
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)
    public string $storageKey; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)
    public CatalogView $view; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    public function __construct(private readonly ShelfProvider $shelfProvider) {}

    /** @return list<array{advance: Advance, product: Product}> */
    public function getRows(): array
    {
        return $this->shelfProvider->rowsFor($this->player, $this->view, $this->storageKey);
    }
}
