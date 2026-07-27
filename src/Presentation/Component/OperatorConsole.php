<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Infrastructure\Repository\OrderRepository;
use App\Rules\Action\FinishGame;
use App\Rules\Action\NextTurn;
use App\Rules\Action\PreviousTurn;
use App\State\Game;
use App\State\Order;
use App\State\Player;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: 'organisms/operatorConsole.html.twig')]
final class OperatorConsole
{
    use DefaultActionTrait;

    #[LiveProp]
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(
        private readonly OrderRepository $orderRepository,
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
