<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Player;

use App\State\Player;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/game/{gameSlug}/player/{playerSlug}', name: 'app_player_board', requirements: ['gameSlug' => '[a-z0-9-]+', 'playerSlug' => '[a-z0-9-]+'], methods: ['GET'])]
final class BoardController extends AbstractController
{
    public function __invoke(#[ValueResolver('player')] Player $player): Response
    {
        if ($player->game->finished) {
            return $this->render('skeletons/player/saga.html.twig', ['player' => $player]);
        }

        return $this->render('skeletons/player/board.html.twig', ['player' => $player]);
    }
}
