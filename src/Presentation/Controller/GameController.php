<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\State\Game;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GameController extends AbstractController
{
    #[Route('/create', name: 'app_game_create', methods: ['GET'], priority: 10)]
    public function create(): Response
    {
        return $this->render('skeletons/gameCreate.html.twig');
    }

    #[Route('/{slug}', name: 'app_game_dashboard', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function dashboard(#[MapEntity(mapping: ['slug' => 'slug'])] Game $game): Response
    {
        if ($game->finished) {
            return $this->chronicle($game);
        }

        return $this->render('skeletons/gameDashboard.html.twig', ['game' => $game]);
    }

    #[Route('/{slug}/operator', name: 'app_game_operator', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function operator(#[MapEntity(mapping: ['slug' => 'slug'])] Game $game): Response
    {
        return $this->render('skeletons/gameOperator.html.twig', ['game' => $game]);
    }

    private function chronicle(Game $game): Response
    {
        return $this->render('skeletons/gameChronicle.html.twig', ['game' => $game]);
    }
}
