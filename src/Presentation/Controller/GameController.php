<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Rules\Ruleset\TradeCardRegistry;
use App\State\Game;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GameController extends AbstractController
{
    public function __construct(private readonly TradeCardRegistry $tradeCardRegistry) {}

    #[Route('/create', name: 'app_game_create', methods: ['GET'], priority: 10)]
    public function create(): Response
    {
        return $this->render('skeletons/Game/create.html.twig');
    }

    #[Route('/{slug}', name: 'app_game_dashboard', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function dashboard(#[MapEntity(mapping: ['slug' => 'slug'])] Game $game): Response
    {
        if ($game->finished) {
            return $this->chronicle($game);
        }

        return $this->render('skeletons/Game/dashboard.html.twig', ['game' => $game]);
    }

    #[Route('/{slug}/operator', name: 'app_game_operator', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function operator(#[MapEntity(mapping: ['slug' => 'slug'])] Game $game): Response
    {
        return $this->render('skeletons/Game/operator.html.twig', ['game' => $game]);
    }

    #[Route('/{slug}/trade-cards', name: 'app_game_trade_cards', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function tradeCards(#[MapEntity(mapping: ['slug' => 'slug'])] Game $game): Response
    {
        return $this->render('skeletons/Game/trade_cards.html.twig', [
            'game' => $game,
            'distribution' => $this->tradeCardRegistry->distributionFor($game->playerCount, $game->region),
        ]);
    }

    private function chronicle(Game $game): Response
    {
        return $this->render('skeletons/Game/chronicle.html.twig', ['game' => $game]);
    }
}
