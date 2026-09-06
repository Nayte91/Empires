<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Operator;

use App\State\Game;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/game/{slug}/operator/unlock', name: 'app_operator_unlock', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
final class UnlockController extends AbstractController
{
    public function __invoke(#[MapEntity(mapping: ['slug' => 'slug'])] Game $game, Request $request): Response
    {
        $candidate = (string) $request->query->get('next', '');
        $prefix = '/game/'.$game->slug.'/operator/';
        $isOperatorScreen = str_starts_with($candidate, $prefix) && $candidate !== $request->getPathInfo();
        $next = $isOperatorScreen ? $candidate : $this->generateUrl('app_operator_board', ['slug' => $game->slug]);

        return $this->render('skeletons/operator/unlock.html.twig', ['game' => $game, 'next' => $next]);
    }
}
