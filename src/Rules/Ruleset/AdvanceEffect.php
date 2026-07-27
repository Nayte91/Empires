<?php

declare(strict_types=1);

namespace App\Rules\Ruleset;

/**
 * The game rules an advance may bend, named by what they do rather than by the advance that grants
 * them. Which advance carries which effect is data — config/game/advances.yaml declares it, and
 * that file also carries the rule each effect stands for.
 *
 * This is the seam that used to be a per-service constant holding an advance key: a calculator
 * names the effect it cares about, never the advance.
 */
enum AdvanceEffect: string
{
    case MovesLast = 'moves_last';
    case TaxRevoltImmunity = 'tax_revolt_immunity';
    case TaxRateChoice = 'tax_rate_choice';
    case TaxRateRaise = 'tax_rate_raise';
    case CityBuildRebate = 'city_build_rebate';
    case ExtraHandCard = 'extra_hand_card';
}
