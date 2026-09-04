<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\State\Game;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/{slug}/operator/', requirements: ['slug' => '[a-z0-9-]+'], methods: [Request::METHOD_GET])]
final class OperatorController extends AbstractController
{
    #[Route('board', name: 'app_operator_board')]
    public function board(#[MapEntity(mapping: ['slug' => 'slug'])] Game $game): Response
    {
        return $this->render('skeletons/operator/board.html.twig', ['game' => $game]);
    }

    #[Route('orders', name: 'app_operator_orders')]
    public function orders(#[MapEntity(mapping: ['slug' => 'slug'])] Game $game): Response
    {
        return $this->render('skeletons/operator/orders.html.twig', ['game' => $game]);
    }

    #[Route('calamities', name: 'app_operator_calamities')]
    public function calamities(#[MapEntity(mapping: ['slug' => 'slug'])] Game $game): Response
    {
        return $this->render('skeletons/operator/calamities.html.twig', ['game' => $game]);
    }

    #[Route('trade', name: 'app_operator_trade')]
    public function trade(#[MapEntity(mapping: ['slug' => 'slug'])] Game $game): Response
    {
        return $this->render('skeletons/operator/trade.html.twig', ['game' => $game]);
    }

    #[Route('abilities', name: 'app_operator_abilities')]
    public function abilities(#[MapEntity(mapping: ['slug' => 'slug'])] Game $game): Response
    {
        return $this->render('skeletons/operator/abilities.html.twig', ['game' => $game]);
    }

    #[Route('pos', name: 'app_operator_pos')]
    public function pos(#[MapEntity(mapping: ['slug' => 'slug'])] Game $game): Response
    {
        return $this->render('skeletons/operator/pos.html.twig', ['game' => $game]);
    }
}
