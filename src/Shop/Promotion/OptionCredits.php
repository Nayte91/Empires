<?php

declare(strict_types=1);

namespace App\Shop\Promotion;

use App\Entity\Player;
use App\Repository\OrderRepository;
use App\Shop\OrderStatus;

final readonly class OptionCredits
{
    public function __construct(
        private OrderRepository $orderRepository,
    ) {}

    /**
     * Sums, per category, every Option allocation validated so far for this
     * player — a Pending order's own allocation is intentionally excluded
     * (no self-crediting), and it naturally stops counting once its order
     * is erased.
     *
     * @return array<string, int>
     */
    public function forPlayer(Player $player): array
    {
        $credits = [];

        foreach ($this->orderRepository->findByPlayer($player) as $order) {
            if (OrderStatus::Validated !== $order->status) {
                continue;
            }

            foreach ($order->lines() as $line) {
                if (!$line->promotion instanceof AppliedPromotion) {
                    continue;
                }
                if (PromotionType::Option !== $line->promotion->type) {
                    continue;
                }
                foreach ($line->promotion->allocation as $category => $amount) {
                    $credits[$category] = ($credits[$category] ?? 0) + $amount;
                }
            }
        }

        return $credits;
    }
}
