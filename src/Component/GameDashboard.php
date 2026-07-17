<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Advance;
use App\Entity\Game;
use App\Entity\Player;
use App\Repository\AdvanceRepository;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Read-only display screen: no writable prop, no action. Re-rendered by a
 * Mercure ping whenever the operator console changes the game.
 */
#[AsLiveComponent(template: 'organisms/gameDashboard.html.twig')]
final class GameDashboard
{
    use DefaultActionTrait;

    #[LiveProp]
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    /** @var array<string, string> */
    private array $qrCache = [];

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AdvanceRepository $advanceRepository,
    ) {}

    public function getOperatorUrl(): string
    {
        return $this->urlGenerator->generate(
            'app_game_operator',
            ['slug' => $this->game->slug],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    public function getOperatorQr(): string
    {
        return $this->buildQr($this->getOperatorUrl());
    }

    public function getPlayerUrl(Player $player): string
    {
        return $this->urlGenerator->generate(
            'app_player_board',
            ['gameSlug' => $this->game->slug, 'playerSlug' => $player->slug],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    public function getPlayerQr(Player $player): string
    {
        return $this->buildQr($this->getPlayerUrl($player));
    }

    // House rule: 1 VP per city (deliberately not the ÷2 formula in sources/rules/07-victory-conditions/scoring-system.md).
    public function getPlayerVictoryPoints(Player $player): int
    {
        $advancePoints = array_sum(array_map(
            static fn (Advance $advance): int => $advance->points,
            $this->advanceRepository->getAdvancesByNames($player->advances)
        ));

        return $advancePoints + $player->cities;
    }

    private function buildQr(string $url): string
    {
        return $this->qrCache[$url] ??= new Builder(
            writer: new SvgWriter(),
            data: $url,
            size: 320,
            margin: 0,
        )->build()->getString();
    }
}
