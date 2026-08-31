<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\State\Game;
use App\State\Player;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The way in to every view of a game — the operator console, then one board per player.
 * Each target names its own QR code so a phone reaches the view without typing the URL.
 *
 * The codes are addresses, not markup: inlining nineteen 320px SVGs made them 60% of the
 * dashboard's weight, for a panel that starts closed and shows one code at a time. The template
 * asks for them with `loading="lazy"`, so the browser fetches none of them until the panel opens.
 */
#[AsTwigComponent(template: 'molecules/Navigation.html.twig')]
final class Navigation
{
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    /** @var null|list<array{key: string, label: string, caption: ?string, empire: ?string, url: string, qrUrl: string}> */
    private ?array $targetsCache = null;

    public function __construct(private readonly UrlGeneratorInterface $urlGenerator) {}

    /**
     * Seat order, not play order: a player looks for their own name, and a list that reshuffles
     * every turn is a list you have to read twice.
     *
     * @return list<array{key: string, label: string, caption: ?string, empire: ?string, url: string, qrUrl: string}>
     */
    public function getTargets(): array
    {
        if (null !== $this->targetsCache) {
            return $this->targetsCache;
        }

        $targets = $this->game->finished ? [] : [$this->operatorTarget()];

        foreach ($this->game->players as $player) {
            $targets[] = $this->playerTarget($player);
        }

        return $this->targetsCache = $targets;
    }

    /**
     * Dropped once the game is finished: the console still answers, but every one of its controls
     * refuses a finished game, so offering the way in would only promise something.
     *
     * @return array{key: string, label: string, caption: ?string, empire: ?string, url: string, qrUrl: string}
     */
    private function operatorTarget(): array
    {
        $url = $this->urlGenerator->generate(
            'app_game_operator',
            ['slug' => $this->game->slug],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return [
            'key' => 'operator',
            'label' => 'Operator',
            'caption' => 'console',
            'empire' => null,
            'url' => $url,
            'qrUrl' => $this->qrUrl('operator'),
        ];
    }

    /**
     * No caption: a player's subtitle is their empire's adjective, which the template reads off the
     * slug through the `empire_adjective` filter. Only a target that has no empire — the operator
     * console — carries a caption of its own.
     *
     * @return array{key: string, label: string, caption: ?string, empire: ?string, url: string, qrUrl: string}
     */
    private function playerTarget(Player $player): array
    {
        $url = $this->urlGenerator->generate(
            'app_player_board',
            ['gameSlug' => $this->game->slug, 'playerSlug' => $player->slug],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return [
            'key' => $player->slug,
            'label' => $player->name,
            'caption' => null,
            'empire' => $player->empire,
            'url' => $url,
            'qrUrl' => $this->qrUrl($player->slug),
        ];
    }

    private function qrUrl(string $key): string
    {
        return $this->urlGenerator->generate('app_game_qr', ['slug' => $this->game->slug, 'key' => $key]);
    }
}
