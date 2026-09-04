<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Operator;

use App\State\Game;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/game/{slug}/operator/abilities', name: 'app_operator_abilities', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
final class AbilitiesController extends AbstractController
{
    public function __invoke(#[MapEntity(mapping: ['slug' => 'slug'])] Game $game): Response
    {
        return $this->render('skeletons/operator/abilities.html.twig', ['game' => $game]);
    }
}
