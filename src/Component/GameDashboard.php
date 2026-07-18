<?php

declare(strict_types=1);

namespace App\Component;

use App\DTO\AstEraDefinition;
use App\Entity\Game;
use App\Entity\Player;
use App\Game\Service\ScoreCalculator;
use App\Repository\AdvanceRepository;
use App\Repository\AstRepository;
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
        private readonly AstRepository $astRepository,
        private readonly ScoreCalculator $scoreCalculator,
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

    /** @return list<AstEraDefinition> */
    public function getAstEras(): array
    {
        return $this->astRepository->getEras();
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

    /** @return list<array{player: Player, url: string, qr: string, victoryPoints: int}> */
    public function getPlayerRows(): array
    {
        $rows = array_map(
            fn (Player $player): array => [
                'player' => $player,
                'url' => $this->getPlayerUrl($player),
                'qr' => $this->getPlayerQr($player),
                'victoryPoints' => $this->getPlayerVictoryPoints($player),
            ],
            $this->game->players->toArray(),
        );

        usort($rows, static fn (array $a, array $b): int => $b['victoryPoints'] <=> $a['victoryPoints']);

        return $rows;
    }

    public function getPlayerVictoryPoints(Player $player): int
    {
        return $this->scoreCalculator->scoreFor(
            $player,
            array_values($this->advanceRepository->getAdvancesByNames($player->advances)),
        );
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
