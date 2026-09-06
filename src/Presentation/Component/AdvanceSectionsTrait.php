<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\PurchaseHistoryCalculator;
use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\State\Player;

trait AdvanceSectionsTrait
{
    /** @return list<array{turn: ?int, advances: list<Advance>}> */
    protected function toAdvanceSections(Player $player, AdvanceRegistry $advanceRegistry, PurchaseHistoryCalculator $purchaseHistoryCalculator): array
    {
        $owned = $player->advances;
        $sections = [];

        foreach (array_reverse($purchaseHistoryCalculator->keysPerTurn($player), true) as $turn => $keys) {
            $bought = array_values(array_intersect($keys, $owned));

            if ([] === $bought) {
                continue;
            }

            $sections[] = ['turn' => $turn, 'advances' => array_values($advanceRegistry->getAdvancesByNames($bought))];
            $owned = array_values(array_diff($owned, $bought));
        }

        if ([] !== $owned) {
            $sections[] = ['turn' => null, 'advances' => array_values($advanceRegistry->getAdvancesByNames($owned))];
        }

        return $sections;
    }
}
