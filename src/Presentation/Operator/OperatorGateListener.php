<?php

declare(strict_types=1);

namespace App\Presentation\Operator;

use App\Presentation\Controller\Operator\LockController;
use App\Presentation\Controller\Operator\UnlockController;
use App\State\Game;
use App\State\Repository\GameRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsEventListener(event: KernelEvents::CONTROLLER)]
final readonly class OperatorGateListener
{
    public function __construct(
        private GameRepositoryInterface $games,
        private UnlockCookie $cookie,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(ControllerEvent $event): void
    {
        $controller = $event->getController();

        if (!\is_object($controller) || !str_starts_with($controller::class, 'App\Presentation\Controller\Operator\\')
            || $controller instanceof UnlockController || $controller instanceof LockController) {
            return;
        }

        $request = $event->getRequest();
        $game = $this->games->findOneBySlug((string) $request->attributes->get('slug'));

        if (!$game instanceof Game || !$game->locked || $this->cookie->isValid($request, $game)) {
            return;
        }

        $event->setController(fn (): Response => new RedirectResponse($this->urlGenerator->generate('app_operator_unlock', [
            'slug' => $game->slug,
            'next' => $request->getPathInfo(),
        ])));
    }
}
