<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Game\Dto\Advance;
use App\Shop\Dto\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: 'organisms/playerBoard.html.twig')]
final class PlayerBoard
{
    use DefaultActionTrait;

    #[LiveProp]
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp(writable: true, onUpdated: 'onCensusUpdated')]
    public int $census = 0; // @phpstan-ignore property.uninitialized (initialized in mount())

    #[LiveProp(writable: true, onUpdated: 'onTreasuryUpdated')]
    public int $treasury = 0; // @phpstan-ignore property.uninitialized (initialized in mount())

    #[LiveProp(writable: true, onUpdated: 'onCardsUpdated')]
    public int $cards = 0; // @phpstan-ignore property.uninitialized (initialized in mount())

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdvanceCatalog $advanceCatalog,
        private readonly HubInterface $hub,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function mount(Player $player): void
    {
        $this->player = $player;
        $this->census = $player->census;
        $this->treasury = $player->treasury;
        $this->cards = $player->cards;
    }

    public function onCensusUpdated(): void
    {
        $this->player->census = $this->census;
        $this->entityManager->flush();
        $this->publish();
        $this->census = $this->player->census;
    }

    public function onTreasuryUpdated(): void
    {
        $this->player->treasury = $this->treasury;
        $this->entityManager->flush();
        $this->publish();
        $this->treasury = $this->player->treasury;
    }

    public function onCardsUpdated(): void
    {
        $this->player->cards = $this->cards;
        $this->entityManager->flush();
        $this->publish();
        $this->cards = $this->player->cards;
    }

    #[LiveAction]
    public function adjustCities(#[LiveArg] int $delta): void
    {
        $this->player->cities += $delta;
        $this->entityManager->flush();
        $this->publish();
    }

    #[LiveAction]
    public function adjustShips(#[LiveArg] int $delta): void
    {
        $this->player->ships += $delta;
        $this->entityManager->flush();
        $this->publish();
    }

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

    private function publish(): void
    {
        $this->hub->publish(new Update(
            'empires/game/'.$this->player->game->id,
            '{"event":"player-updated"}',
        ));
    }
}
