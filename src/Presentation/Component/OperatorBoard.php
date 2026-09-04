<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Action\FinishGame;
use App\Rules\Action\NextTurn;
use App\Rules\Action\PreviousTurn;
use App\State\Game;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The operator screen is deliberately operational only, counters and commands: advisories live in
 * the Outlook block, which the board does not carry.
 *
 * It shares the player board's ControlBoard, but drives every player from one screen — a link into
 * a single player's shop belongs to that player's own board, so the board omits it. Its stat list
 * is intentionally shorter than the player's, and nothing else guards them against converging back:
 * this page tracks how far along each empire is, economy management belongs to the player's own
 * board.
 */
#[AsLiveComponent(template: 'organisms/OperatorBoard.html.twig')]
final class OperatorBoard
{
    use DefaultActionTrait;

    #[LiveProp]
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(private readonly MessageBusInterface $commandBus) {}

    #[LiveAction]
    public function nextTurn(): void
    {
        $this->commandBus->dispatch(new NextTurn($this->game->id));
    }

    #[LiveAction]
    public function previousTurn(): void
    {
        $this->commandBus->dispatch(new PreviousTurn($this->game->id));
    }

    #[LiveAction]
    public function finishGame(): void
    {
        $this->commandBus->dispatch(new FinishGame($this->game->id));
    }
}
