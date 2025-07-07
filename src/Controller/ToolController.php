<?php

namespace App\Controller;

use App\Repository\Advances;
use App\Repository\Civilizations;
use App\Repository\GameData;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/tool')]
final class ToolController extends AbstractController
{
    #[Route('/marketplace', name: 'app_tool_marketplace')]
    public function marketplace(): Response
    {
        return $this->render('tool/marketplace.html.twig', ['controller_name' => 'GameController']);
    }

    #[Route('/destiny', name: 'app_tool_destiny')]
    public function destiny(Advances $advanceRepository): Response
    {
        return $this->render('tool/destiny.html.twig', ['advances' => $advanceRepository->getAdvances()]);
    }

    #[Route('/census', name: 'app_tool_census')]
    public function ast(GameData $gameDataService, Civilizations $civilizationsService): Response
    {
        return $this->render('tool/census.html.twig', [
            'regions' => $gameDataService->getRegions(),
            'civilizations' => $civilizationsService->getCivilizations(),
        ]);
    }
}
