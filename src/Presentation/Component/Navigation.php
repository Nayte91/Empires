<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\EmpireRegistry;
use App\State\Game;
use App\State\Player;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The way in to every view of a game — the operator console, then one board per player.
 * Each target carries its own QR code so a phone reaches the view without typing the URL.
 */
#[AsTwigComponent(template: 'molecules/navigation.html.twig')]
final class Navigation
{
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    /** @var null|list<array{key: string, label: string, caption: ?string, empire: ?string, url: string, qr: string}> */
    private ?array $targetsCache = null;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EmpireRegistry $empireRegistry,
    ) {}

    /**
     * Seat order, not play order: a player looks for their own name, and a list that reshuffles
     * every turn is a list you have to read twice.
     *
     * @return list<array{key: string, label: string, caption: ?string, empire: ?string, url: string, qr: string}>
     */
    public function getTargets(): array
    {
        if (null !== $this->targetsCache) {
            return $this->targetsCache;
        }

        $operatorUrl = $this->urlGenerator->generate(
            'app_game_operator',
            ['slug' => $this->game->slug],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $targets = [[
            'key' => 'operator',
            'label' => 'Operator',
            'caption' => 'console',
            'empire' => null,
            'url' => $operatorUrl,
            'qr' => $this->buildQr($operatorUrl),
        ]];

        foreach ($this->game->players as $player) {
            $targets[] = $this->playerTarget($player);
        }

        return $this->targetsCache = $targets;
    }

    /** @return array{key: string, label: string, caption: ?string, empire: ?string, url: string, qr: string} */
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
            'caption' => null === $player->empire ? null : $this->empireRegistry->findByName($player->empire)?->adjective,
            'empire' => $player->empire,
            'url' => $url,
            'qr' => $this->buildQr($url),
        ];
    }

    private function buildQr(string $url): string
    {
        return new Builder(
            writer: new SvgWriter(),
            data: $url,
            size: 320,
            margin: 0,
        )->build()->getString();
    }
}
