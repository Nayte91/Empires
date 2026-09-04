<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Rules\Ruleset\ScenarioRegistry;
use App\Rules\Ruleset\TradeCardRegistry;
use App\State\Game;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/game')]
final class GameController extends AbstractController
{
    public function __construct(
        private readonly TradeCardRegistry $tradeCardRegistry,
        private readonly ScenarioRegistry $scenarioRegistry,
    ) {}

    #[Route('/{slug}', name: 'app_game_dashboard', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function dashboard(#[MapEntity(mapping: ['slug' => 'slug'])] Game $game): Response
    {
        if ($game->finished) {
            return $this->chronicle($game);
        }

        return $this->render('skeletons/game/dashboard.html.twig', ['game' => $game]);
    }

    #[Route('/{slug}/trade-cards', name: 'app_game_trade_cards', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function tradeCards(#[MapEntity(mapping: ['slug' => 'slug'])] Game $game): Response
    {
        return $this->render('skeletons/game/trade_cards.html.twig', [
            'game' => $game,
            'distribution' => $this->tradeCardRegistry->distributionFor(
                $this->scenarioRegistry->find($game->playerCount, $game->region),
            ),
        ]);
    }

    #[Route('/{slug}/qr/{key}', name: 'app_game_qr', requirements: ['slug' => '[a-z0-9-]+', 'key' => '[a-z0-9-]+'], methods: ['GET'])]
    public function qrCode(#[MapEntity(mapping: ['slug' => 'slug'])] Game $game, string $key): Response
    {
        $svg = new Builder(
            writer: new SvgWriter(),
            data: $this->qrTargetUrl($game, $key),
            size: 320,
            margin: 0,
        )->build()->getString();

        $response = new Response($svg, Response::HTTP_OK, ['Content-Type' => 'image/svg+xml']);
        $response->setPublic()->setMaxAge(86400);

        return $response;
    }

    private function qrTargetUrl(Game $game, string $key): string
    {
        if ('operator' === $key) {
            return $this->generateUrl('app_operator_board', ['slug' => $game->slug], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        foreach ($game->players as $player) {
            if ($player->slug === $key) {
                return $this->generateUrl(
                    'app_player_board',
                    ['gameSlug' => $game->slug, 'playerSlug' => $player->slug],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                );
            }
        }

        throw $this->createNotFoundException(sprintf('No QR target "%s" in game "%s".', $key, $game->slug));
    }

    private function chronicle(Game $game): Response
    {
        return $this->render('skeletons/game/chronicle.html.twig', ['game' => $game]);
    }
}
