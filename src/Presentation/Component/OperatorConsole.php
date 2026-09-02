<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Action\FinishGame;
use App\Rules\Action\NextTurn;
use App\Rules\Action\PreviousTurn;
use App\State\Game;
use App\State\Order;
use App\State\Player;
use App\State\Repository\OrderRepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The operator screen is deliberately operational only, counters and commands: advisories live in
 * the Outlook block, which the console does not carry.
 *
 * It shares the player board's ControlBoard, but drives every player from one screen — a link into
 * a single player's shop belongs to that player's own board, so the console omits it. Its two stat
 * lists are intentionally different, and nothing else guards them against converging back: the
 * General tab tracks how far along each empire is, economy management belongs to the player's own
 * tab.
 *
 * It is also the one screen still answering for a finished game, so its title qualifier says so.
 */
#[AsLiveComponent(template: 'organisms/OperatorConsole.html.twig')]
final class OperatorConsole
{
    use DefaultActionTrait;

    #[LiveProp]
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly MessageBusInterface $commandBus,
    ) {}

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

    /**
     * Fingerprint of a player's orders, used as the `ordersStamp` prop of its
     * PlayerOrders child: any turn/status/validation-time change yields a new
     * string, forcing the child to remount fresh (see organisms/playerOrders).
     *
     * Prefixed with the game's current turn so that advancing/rewinding turns
     * always changes the stamp — even with zero orders — since PlayerOrders'
     * card list (one per turn down to 1) depends on currentTurn too.
     */
    public function ordersStampFor(Player $player): string
    {
        $orders = $this->orderRepository->findByPlayer($player);

        $ordersStamp = implode('|', array_map(
            static fn (Order $order): string => $order->turn.':'.$order->status->value.':'.($order->validatedAt?->format('c') ?? ''),
            $orders,
        ));

        return 'T'.$player->game->currentTurn.('' === $ordersStamp ? '' : '|'.$ordersStamp);
    }
}
