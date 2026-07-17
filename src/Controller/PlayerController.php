<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Player;
use App\Repository\EmpireRepository;
use App\Repository\PlayerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlayerController extends AbstractController
{
    public function __construct(
        private readonly PlayerRepository $playerRepository,
        private readonly EmpireRepository $empireRepository,
    ) {}

    #[Route('/game/{gameSlug}/player/{playerSlug}', name: 'app_player_board', methods: ['GET'])]
    public function board(string $gameSlug, string $playerSlug): Response
    {
        $player = $this->findPlayer($gameSlug, $playerSlug);

        return $this->render('skeletons/playerBoard.html.twig', [
            'player' => $player,
            'empireColor' => $this->empireColorFor($player),
        ]);
    }

    #[Route('/game/{gameSlug}/player/{playerSlug}/shop', name: 'app_player_shop', methods: ['GET'])]
    public function shop(string $gameSlug, string $playerSlug): Response
    {
        $player = $this->findPlayer($gameSlug, $playerSlug);

        return $this->render('skeletons/shop.html.twig', [
            'player' => $player,
            'empireColor' => $this->empireColorFor($player),
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

    private function empireColorFor(Player $player): ?string
    {
        if (null === $player->empire) {
            return null;
        }

        return $this->empireRepository->findByName($player->empire)?->color;
    }
}
