<?php

declare(strict_types=1);

namespace App\Presentation\Advisory;

use App\Rules\HandSizeCalculator;
use App\State\Player;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 10)]
final readonly class HandLimitRule implements AdvisoryRule
{
    public function __construct(private HandSizeCalculator $handSizeCalculator) {}

    public function evaluate(Player $player): ?Advisory
    {
        $excess = $this->handSizeCalculator->excessFor($player);

        if (0 === $excess) {
            return null;
        }

        return new Advisory(
            sprintf('You must discard %d %s!', $excess, 1 === $excess ? 'card' : 'cards'),
            AdvisoryLevel::Danger,
        );
    }
}
