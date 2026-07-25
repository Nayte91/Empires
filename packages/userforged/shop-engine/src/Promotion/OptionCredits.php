<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Promotion;

use Userforged\ShopEngine\Dto\OrderLine;

final class OptionCredits
{
    /**
     * Sums, per facet, every Option allocation carried by the given lines —
     * a pure function over already-fetched, already-filtered lines. Callers
     * decide which lines are "confirmed" (in Empires: validated orders only,
     * see App\Game\Shop\ShopConnector::buyerFor()) — this class no longer
     * knows what confirmed means, only how to add allocations up.
     *
     * @param list<OrderLine> $confirmedLines
     *
     * @return array<string, int>
     */
    public static function aggregate(array $confirmedLines): array
    {
        $credits = [];

        foreach ($confirmedLines as $line) {
            if (!$line->promotion instanceof AppliedPromotion) {
                continue;
            }
            if (PromotionType::Option !== $line->promotion->type) {
                continue;
            }
            foreach ($line->promotion->allocation as $facet => $amount) {
                $credits[$facet] = ($credits[$facet] ?? 0) + $amount;
            }
        }

        return $credits;
    }
}
