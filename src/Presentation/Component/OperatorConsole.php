<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Infrastructure\Repository\OrderRepository;
use App\State\GameSession;
use App\State\Order;
use App\State\Player;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: 'organisms/operatorConsole.html.twig')]
final class OperatorConsole
{
    use DefaultActionTrait;

    #[LiveProp]
    public GameSession $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderRepository $orderRepository,
        private readonly HubInterface $hub,
    ) {}

    #[LiveAction]
    public function nextTurn(): void
    {
        if ($this->game->finished) {
            return;
        }

        ++$this->game->currentTurn;
        $this->entityManager->flush();
        $this->publish();
    }

    #[LiveAction]
    public function previousTurn(): void
    {
        if ($this->game->finished) {
            return;
        }

        --$this->game->currentTurn;
        $this->entityManager->flush();
        $this->publish();
    }

    #[LiveAction]
    public function finishGame(): void
    {
        if ($this->game->finished) {
            return;
        }

        $this->game->finishedAt = new \DateTimeImmutable();
        $this->entityManager->flush();
        $this->publish();
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

    private function publish(): void
    {
        $this->hub->publish(new Update(
            'empires/game/'.$this->game->id,
            '{"event":"game-updated"}',
        ));
    }
}
