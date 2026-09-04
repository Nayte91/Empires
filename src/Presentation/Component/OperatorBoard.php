<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Action\FinishGame;
use App\Rules\Action\NextTurn;
use App\Rules\Action\PreviousTurn;
use App\Rules\Action\Stat;
use App\State\Game;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The operator screen is deliberately operational only, counters and commands: advisories live in
 * the Outlook block, which the board does not carry.
 */
#[AsLiveComponent(template: 'organisms/OperatorBoard.html.twig')]
final class OperatorBoard
{
    use DefaultActionTrait;

    #[LiveProp]
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(private readonly MessageBusInterface $commandBus) {}

    /**
     * The board tracks how far along each empire is; economy stays on the player's own board.
     *
     * @return list<Stat>
     */
    public function getTrackedStats(): array
    {
        return [Stat::Census, Stat::Cities, Stat::AstPosition];
    }

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
