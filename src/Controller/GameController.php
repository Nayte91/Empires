<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GameSession;
use App\Game\AstCatalog;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GameController extends AbstractController
{
    public function __construct(private readonly AstCatalog $astCatalog) {}

    #[Route('/create', name: 'app_game_create', methods: ['GET'], priority: 10)]
    public function create(): Response
    {
        return $this->render('skeletons/gameCreate.html.twig');
    }

    #[Route('/{slug}', name: 'app_game_dashboard', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function dashboard(#[MapEntity(mapping: ['slug' => 'slug'])] GameSession $game): Response
    {
        return $this->render('skeletons/gameDashboard.html.twig', ['game' => $game]);
    }

    #[Route('/{slug}/ast', name: 'app_game_ast', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function ast(
        #[MapEntity(mapping: ['slug' => 'slug'])]
        GameSession $game,
    ): Response {
        return $this->render('skeletons/gameAst.html.twig', [
            'game' => $game,
            'eras' => $this->astCatalog->getEras(),
        ]);
    }

    #[Route('/{slug}/census', name: 'app_game_census', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function census(#[MapEntity(mapping: ['slug' => 'slug'])] GameSession $game): Response
    {
        return $this->render('skeletons/gameCensus.html.twig', ['game' => $game]);
    }

    #[Route('/{slug}/operator', name: 'app_game_operator', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function operator(#[MapEntity(mapping: ['slug' => 'slug'])] GameSession $game): Response
    {
        return $this->render('skeletons/gameOperator.html.twig', ['game' => $game]);
    }
}
