<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Action\RenamePlayer;
use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\ScoreCalculator;
use App\State\Player;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\ValidatableComponentTrait;

#[AsLiveComponent(template: 'organisms/playerBoard.html.twig')]
final class PlayerBoard
{
    use DefaultActionTrait;
    use ValidatableComponentTrait;

    #[LiveProp]
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated in mount())

    #[LiveProp(writable: true)]
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Player name is required.', normalizer: [self::class, 'slugify']),
        new Assert\Length(max: Player::MAX_NAME_LENGTH, maxMessage: 'Name cannot be longer than {{ limit }} characters.'),
        new Assert\Expression('not this.isNameTaken(value)', message: 'Name already taken.'),
    ])]
    public string $newName = ''; // @phpstan-ignore property.uninitialized (hydrated in mount())

    public function __construct(
        private readonly AdvanceRegistry $advanceRegistry,
        private readonly ScoreCalculator $scoreCalculator,
        private readonly MessageBusInterface $commandBus,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function mount(Player $player): void
    {
        $this->player = $player;
        $this->newName = $player->name;
    }

    /**
     * Whether another player in the same game already carries this name — compared on the slug,
     * per Player's own uniqueness constraint (`uniq_player_game_slug`), not the raw string.
     */
    public function isNameTaken(string $name): bool
    {
        $slug = self::slugify($name);

        return $this->player->game->players->exists(
            fn (int $key, Player $other): bool => $other !== $this->player && $other->slug === $slug,
        );
    }

    /**
     * @see \App\Engine\Handler\RenamePlayerHandler for the write; redirects on success because the
     * rename changes the player's own slug, which is the URL segment this very page is served under.
     */
    #[LiveAction]
    public function rename(): ?Response
    {
        $this->validateField('newName', false);

        if ([] !== $this->getErrors('newName')) {
            return null;
        }

        $this->commandBus->dispatch(new RenamePlayer($this->player->id, ucfirst(trim($this->newName))));

        return new RedirectResponse($this->urlGenerator->generate('app_player_board', [
            'gameSlug' => $this->player->game->slug,
            'playerSlug' => $this->player->slug,
        ]));
    }

    public static function slugify(string $value): string
    {
        return strtolower((string) new AsciiSlugger()->slug($value));
    }

    /** @return list<Advance> */
    public function getOwnedAdvances(): array
    {
        return array_values($this->advanceRegistry->getAdvancesByNames($this->player->advances));
    }

    /** What the owned advances alone are worth, for the Advances heading. */
    public function getAdvancePoints(): int
    {
        return $this->scoreCalculator->advancePointsFor($this->getOwnedAdvances());
    }
}
