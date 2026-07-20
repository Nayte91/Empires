<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Game\Dto\Advance;
use App\Shop\Dto\Product;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: 'organisms/playerBoard.html.twig')]
final class PlayerBoard
{
    use DefaultActionTrait;

    #[LiveProp]
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(
        private readonly AdvanceCatalog $advanceCatalog,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    /** @return list<Advance> */
    public function getOwnedAdvances(): array
    {
        return array_values($this->advanceCatalog->getAdvancesByNames($this->player->advances));
    }

    /**
     * Owned advances wrapped as read-only {@see Product} DTOs, for reuse of the productCard molecule.
     *
     * @return list<Product>
     */
    public function getOwnedProducts(): array
    {
        return array_map(
            static fn (Advance $advance): Product => new Product(
                advance: $advance,
                netCost: $advance->cost,
                owned: true,
                inCart: false,
            ),
            $this->getOwnedAdvances(),
        );
    }

    public function getShopUrl(): string
    {
        return $this->urlGenerator->generate('app_player_shop', [
            'gameSlug' => $this->player->game->slug,
            'playerSlug' => $this->player->slug,
        ]);
    }
}
