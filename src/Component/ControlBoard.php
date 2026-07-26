<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Player;
use App\Game\Dto\Advisory;
use App\Game\Service\PlayerAdvisor;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'molecules:ControlBoard', template: 'molecules/ControlBoard.html.twig')]
final class ControlBoard
{
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    /** @var list<string> */
    public array $stats = ['cities', 'ships', 'census', 'treasury', 'cards'];

    /** Off by default: a link to a player's own shop only makes sense on that player's own board. */
    public bool $withShopLink = false;

    public function __construct(
        private readonly PlayerAdvisor $playerAdvisor,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    /** @return list<Advisory> */
    public function getAdvisories(): array
    {
        return $this->playerAdvisor->advisoriesFor($this->player);
    }

    public function getShopUrl(): string
    {
        return $this->urlGenerator->generate('app_player_shop', [
            'gameSlug' => $this->player->game->slug,
            'playerSlug' => $this->player->slug,
        ]);
    }
}
