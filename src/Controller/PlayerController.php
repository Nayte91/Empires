<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Player;
use App\Repository\PlayerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlayerController extends AbstractController
{
    public function __construct(
        private readonly PlayerRepository $playerRepository,
    ) {}

    #[Route('/{gameSlug}/player/{playerSlug}', name: 'app_player_board', requirements: ['gameSlug' => '[a-z0-9-]+', 'playerSlug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function board(string $gameSlug, string $playerSlug): Response
    {
        $player = $this->findPlayer($gameSlug, $playerSlug);

        return $this->render('skeletons/playerBoard.html.twig', [
            'player' => $player,
        ]);
    }

    #[Route('/{gameSlug}/player/{playerSlug}/shop', name: 'app_player_shop', requirements: ['gameSlug' => '[a-z0-9-]+', 'playerSlug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function shop(string $gameSlug, string $playerSlug): Response
    {
        $player = $this->findPlayer($gameSlug, $playerSlug);

        return $this->render('skeletons/shop.html.twig', [
            'player' => $player,
        ]);
    }

    private function findPlayer(string $gameSlug, string $playerSlug): Player
    {
        $player = $this->playerRepository->findOneByGameSlugAndSlug($gameSlug, $playerSlug);

        if (!$player instanceof Player) {
            throw $this->createNotFoundException();
        }

        return $player;
    }
}
