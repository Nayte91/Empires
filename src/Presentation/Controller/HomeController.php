<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Infrastructure\Repository\GameSessionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(private readonly GameSessionRepository $gameRepository) {}

    #[Route('', name: 'app_home', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('skeletons/home.html.twig', ['games' => $this->gameRepository->findInProgress()]);
    }
}
