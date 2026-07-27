<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Player;
use App\Game\Dto\Advisory;
use App\Game\Service\PlayerAdvisor;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Everything the game has to say to one player, collected into a single list. It decides nothing
 * and computes nothing: each line is the answer of a rule, and the rules are discovered.
 */
#[AsTwigComponent(name: 'molecules:Outlook', template: 'molecules/Outlook.html.twig')]
final class Outlook
{
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    public function __construct(private readonly PlayerAdvisor $playerAdvisor) {}

    /** @return list<Advisory> */
    public function getAdvisories(): array
    {
        return $this->playerAdvisor->advisoriesFor($this->player);
    }
}
