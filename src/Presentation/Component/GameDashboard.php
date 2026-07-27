<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\State\Game;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/** Static shell containing game metadata and embedded live components (AST, ScoreBoard). */
#[AsTwigComponent(template: 'organisms/gameDashboard.html.twig')]
final class GameDashboard
{
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)
    private ?string $operatorQrCache = null;

    public function __construct(private readonly UrlGeneratorInterface $urlGenerator) {}

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
        return $this->operatorQrCache ??= new Builder(
            writer: new SvgWriter(),
            data: $this->getOperatorUrl(),
            size: 320,
            margin: 0,
        )->build()->getString();
    }
}
