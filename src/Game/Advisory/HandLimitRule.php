<?php

declare(strict_types=1);

namespace App\Game\Advisory;

use App\Entity\Player;
use App\Game\AdvisoryLevel;
use App\Game\Dto\Advisory;
use App\Game\Service\HandSizeCalculator;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 10)]
final readonly class HandLimitRule implements AdvisoryRule
{
    public function __construct(private HandSizeCalculator $handSizeCalculator) {}

    public function evaluate(Player $player): ?Advisory
    {
        if ($this->handSizeCalculator->isOverLimit($player)) {
            return new Advisory('You must discard a card!', AdvisoryLevel::Danger);
        }

        return null;
    }
}
