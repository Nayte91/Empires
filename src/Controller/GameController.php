<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GameSession;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GameController extends AbstractController
{
    #[Route('/game/create', name: 'app_game_create', methods: ['GET'])]
    public function create(): Response
    {
        return $this->render('skeletons/gameCreate.html.twig');
    }

    #[Route('/game/{slug}', name: 'app_game_dashboard', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function dashboard(#[MapEntity(mapping: ['slug' => 'slug'])] GameSession $game): Response
    {
        return $this->render('skeletons/gameDashboard.html.twig', ['game' => $game]);
    }

    #[Route('/game/{slug}/operator', name: 'app_game_operator', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function operator(#[MapEntity(mapping: ['slug' => 'slug'])] GameSession $game): Response
    {
        return $this->render('skeletons/gameOperator.html.twig', ['game' => $game]);
    }
}
