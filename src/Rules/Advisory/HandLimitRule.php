<?php

declare(strict_types=1);

namespace App\Rules\Advisory;

use App\Rules\HandSizeCalculator;
use App\State\Player;
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
