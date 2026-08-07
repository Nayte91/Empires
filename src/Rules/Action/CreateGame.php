<?php

declare(strict_types=1);

namespace App\Rules\Action;

use App\Rules\Action\Validator\AvailableGameSlug;
use App\State\Game;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Lengths come from the entity, which is the single authority on how wide a field may be.
 * Asserting them here is what actually enforces them: SQLite ignores a declared VARCHAR
 * width and stores any length, so the column bounds nothing on its own — in tests or in
 * production, both of which run SQLite.
 */
final class CreateGame
{
    #[AvailableGameSlug]
    #[Assert\Length(max: Game::MAX_SLUG_LENGTH, maxMessage: 'The address this name builds is longer than {{ limit }} characters.')]
    public string $slug = '';

    public int $playerCount = 9;

    #[Assert\Length(max: Game::MAX_REGION_LENGTH, maxMessage: 'Region cannot be longer than {{ limit }} characters.')]
    public ?string $region = 'west';

    #[Assert\Choice(choices: ['basic', 'expert'])]
    public string $astVersion = 'basic';

    /** @var list<array{name: string, empire: string}> */
    public array $players = [];
}
