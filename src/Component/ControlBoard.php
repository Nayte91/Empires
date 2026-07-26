<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Player;
use App\Game\Dto\Advisory;
use App\Game\Service\PlayerAdvisor;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'molecules:ControlBoard', template: 'molecules/ControlBoard.html.twig')]
final class ControlBoard
{
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    /** @var list<string> */
    public array $stats = ['cities', 'ships', 'census', 'treasury', 'cards'];

    public function __construct(private readonly PlayerAdvisor $playerAdvisor) {}

    /** @return list<Advisory> */
    public function getAdvisories(): array
    {
        return $this->playerAdvisor->advisoriesFor($this->player);
    }
}
