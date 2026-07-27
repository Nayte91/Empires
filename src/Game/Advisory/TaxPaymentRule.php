<?php

declare(strict_types=1);

namespace App\Game\Advisory;

use App\Entity\Player;
use App\Game\AdvisoryLevel;
use App\Game\Dto\Advisory;
use App\Game\Service\TaxCalculator;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 20)]
final readonly class TaxPaymentRule implements AdvisoryRule
{
    public function __construct(private TaxCalculator $taxCalculator) {}

    public function evaluate(Player $player): ?Advisory
    {
        if ($this->taxCalculator->citiesRevolt($player)) {
            return new Advisory("You can't pay your taxes!", AdvisoryLevel::Danger);
        }

        return null;
    }
}
