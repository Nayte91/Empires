<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\Rulebook;
use App\Rules\Ruleset\RulebookRegistry;
use App\State\Game;
use App\State\Region;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(template: 'molecules/Help.html.twig')]
final class Help
{
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    public function __construct(private readonly RulebookRegistry $rulebooks) {}

    /** @return list<Rulebook> */
    public function getDocuments(): array
    {
        $region = $this->game->region;

        if ($region instanceof Region) {
            return [$this->rulebooks->forRegion($region)];
        }

        return [
            $this->rulebooks->scenarios(),
            $this->rulebooks->forRegion(Region::cases()[random_int(0, \count(Region::cases()) - 1)]),
        ];
    }
}
