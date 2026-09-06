<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Operator;

use App\Presentation\Operator\UnlockCookie;
use App\State\Game;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/game/{slug}/operator/lock', name: 'app_operator_lock', requirements: ['slug' => '[a-z0-9-]+'], methods: ['POST'])]
final class LockController extends AbstractController
{
    public function __construct(private readonly UnlockCookie $cookie) {}

    public function __invoke(#[MapEntity(mapping: ['slug' => 'slug'])] Game $game): RedirectResponse
    {
        $response = $this->redirectToRoute('app_operator_unlock', ['slug' => $game->slug]);
        $response->headers->setCookie($this->cookie->revoke($game));

        return $response;
    }
}
