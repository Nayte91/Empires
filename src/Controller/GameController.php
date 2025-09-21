<?php

namespace App\Controller;

use App\Repository\Civilizations;
use App\Repository\GameData;
use App\Repository\GameRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GameController extends AbstractController
{
    #[Route('/game', name: 'app_game')]
    public function index(): Response
    {
        return $this->render('game/index.html.twig', [
            'controller_name' => 'GameController',
        ]);
    }

    #[Route('/ast', name: 'app_game_ast')]
    public function ast(GameData $gameDataService, Civilizations $civilizationsService): Response
    {
        $regions = $gameDataService->getRegions();

        return $this->render('game/ast.html.twig', [
            'regions' => $regions,
            'civilizations' => $civilizationsService->getCivilizations(),
        ]);
    }

    #[Route('/game/session', name: 'game_session_delete', methods: ['DELETE'])]
    public function deleteSession(GameRepository $gameRepository): JsonResponse
    {
        $gameRepository->clear();
        
        return new JsonResponse(['status' => 'success']);
    }
}
