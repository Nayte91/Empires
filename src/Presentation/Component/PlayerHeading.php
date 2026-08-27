<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Action\RenamePlayer;
use App\State\Player;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\ValidatableComponentTrait;

#[AsLiveComponent(name: 'molecules:PlayerHeading', template: 'molecules/PlayerHeading.html.twig')]
final class PlayerHeading
{
    use DefaultActionTrait;
    use ValidatableComponentTrait;

    #[LiveProp]
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated in mount())

    #[LiveProp(writable: true)]
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Player name is required.', normalizer: [Player::class, 'slugify']),
        new Assert\Length(max: Player::MAX_NAME_LENGTH, maxMessage: 'Name cannot be longer than {{ limit }} characters.'),
        new Assert\Expression('not this.isNameTaken(value)', message: 'Name already taken.'),
    ])]
    public string $newName = ''; // @phpstan-ignore property.uninitialized (hydrated in mount())

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function mount(Player $player): void
    {
        $this->player = $player;
        $this->newName = $player->name;
    }

    public function isNameTaken(string $name): bool
    {
        $slug = Player::slugify($name);

        return $this->player->game->players->exists(
            fn (int $key, Player $other): bool => $other !== $this->player && $other->slug === $slug,
        );
    }

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
}
