<?php

declare(strict_types=1);

namespace App\Presentation\Advisory;

use App\Rules\Ruleset\AstRegistry;
use App\State\ASTVersion;
use App\State\Player;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 1)]
final readonly class AstAdvancementRule implements AdvisoryRule
{
    public function __construct(private AstRegistry $astRegistry) {}

    // REFACTOR-WHEN: advances/min_advance_cost/max_advance_cost/advance_points requirements
    // are confirmed and need checking too — currently only `cities` is verified.
    public function evaluate(Player $player): ?Advisory
    {
        $group = $this->astRegistry->resolveEmpireGroup($player->game->astVersion, $player->empire);
        $trackLength = $this->astRegistry->getTrackLength($player->game->astVersion, $group);
        $nextPosition = $player->astPosition + 1;

        if ($nextPosition > $trackLength - 1) {
            return null;
        }

        $nextEra = $this->astRegistry->getEraForPosition($nextPosition, $player->game->astVersion, $group);

        $requirements = ASTVersion::EXPERT === $player->game->astVersion
            ? $nextEra->expertRequirements
            : $nextEra->basicRequirements;

        $requiredCities = $requirements['cities'] ?? null;

        if (null === $requiredCities || $player->cities >= $requiredCities) {
            return null;
        }

        return new Advisory(
            sprintf('%d cities are required for the %s.', $requiredCities, $nextEra->name),
            AdvisoryLevel::Caution,
        );
    }
}
