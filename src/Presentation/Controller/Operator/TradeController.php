<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Operator;

use App\State\Game;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/game/{slug}/operator/trade', name: 'app_operator_trade', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
final class TradeController extends AbstractController
{
    public function __invoke(#[MapEntity(mapping: ['slug' => 'slug'])] Game $game): Response
    {
        return $this->render('skeletons/operator/trade.html.twig', ['game' => $game]);
    }
}
