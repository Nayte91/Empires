<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Rules\Action\CreateGame;
use App\Rules\HandSizeCalculator;

/**
 * The lines shown under the creation form, describing the game being composed — not the scenario
 * it may end up matching, which is why it takes the command and survives a count no scenario
 * covers. Each line is a finished English sentence, so it is copy, and copy lives here rather
 * than in Rules/ — the same seam as Presentation/Advisory/.
 */
final readonly class CreationSummarizer
{
    public function __construct(private HandSizeCalculator $handSizeCalculator) {}

    // REFACTOR-WHEN: a 2nd line joins the card limit — reinstate one describer per line behind an
    // iterable, under Presentation/, the way Advisory/ already holds several rules. This was that
    // shape (an interface, one implementation, a tagged iterator) for a single sentence.
    /**
     * The base limit, deliberately: at creation nobody owns an advance that could raise it yet.
     *
     * @return list<string>
     */
    public function summarize(CreateGame $game): array
    {
        return [sprintf('Card limit: %d', $this->handSizeCalculator->baseLimitFor($game->playerCount))];
    }
}
