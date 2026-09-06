<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Presentation\Operator\UnlockCookie;
use App\Rules\OperatorAccessRule;
use App\State\Game;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: 'organisms/OperatorUnlock.html.twig')]
final class OperatorUnlock
{
    use DefaultActionTrait;

    #[LiveProp]
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp]
    public string $next; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp(writable: true)]
    public string $pin = '';

    public ?string $error = null;

    public function __construct(
        private readonly OperatorAccessRule $operatorAccessRule,
        private readonly UnlockCookie $cookie,
    ) {}

    #[LiveAction]
    public function unlock(): ?Response
    {
        if (!$this->operatorAccessRule->unlocks($this->game, $this->pin)) {
            $this->error = 'Wrong PIN — ask whoever created the game.';
            $this->pin = '';

            return null;
        }

        $response = new RedirectResponse($this->next);
        $response->headers->setCookie($this->cookie->grant($this->game));

        return $response;
    }
}
