<?php

declare(strict_types=1);

namespace App\Game\Advisory;

use App\Entity\Player;
use App\Game\AdvisoryLevel;
use App\Game\Dto\Advisory;
use App\Game\Service\TaxCalculator;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The quantitative twin of {@see TaxPaymentRule}. Immunity is stated rather than hidden: a player
 * whose cities never revolt should know it, not merely see no warning.
 */
#[AsTaggedItem(priority: -30)]
final readonly class TaxStockRule implements AdvisoryRule
{
    public function __construct(private TaxCalculator $taxCalculator) {}

    public function evaluate(Player $player): Advisory
    {
        if ($this->taxCalculator->isImmune($player)) {
            return new Advisory('Your cities never revolt over taxes', AdvisoryLevel::Good);
        }

        $missing = $this->taxCalculator->stockToRecover($player);

        if (0 === $missing) {
            return new Advisory('Your stock covers your taxes', AdvisoryLevel::Neutral);
        }

        return new Advisory(
            sprintf('You must recover %d stock to pay your taxes', $missing),
            AdvisoryLevel::Caution,
        );
    }
}
