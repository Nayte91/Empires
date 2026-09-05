<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Presentation\Shop\OrderCardFilter;
use App\Presentation\Shop\OrderCardProvider;
use App\Presentation\Shop\OrderCardSort;
use App\Rules\Shop\ShopConnector;
use App\State\Game;
use App\State\Player;
use App\State\Repository\PlayerRepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Userforged\ShopEngine\Command\EraseOrders;

/** @phpstan-import-type OrderCard from OrderCardProvider */
#[AsLiveComponent(template: 'organisms/OperatorOrders.html.twig')]
final class OperatorOrders
{
    use DefaultActionTrait;

    #[LiveProp]
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp(writable: true, url: true)]
    public OrderCardFilter $filter = OrderCardFilter::Pending;

    public function __construct(
        private readonly OrderCardProvider $orderCardProvider,
        private readonly PlayerRepositoryInterface $playerRepository,
        private readonly ShopConnector $shopConnector,
        private readonly MessageBusInterface $commandBus,
    ) {}

    /** @return list<OrderCard> */
    public function getCards(): array
    {
        return array_values(array_filter(
            $this->orderCardProvider->cardsFor($this->game, OrderCardSort::Urgency),
            $this->filter->accepts(...),
        ));
    }

    /** @return array{pending: int, validated: int, missing: int} */
    public function getCounts(): array
    {
        $currentTurn = $this->game->currentTurn;

        $counts = array_count_values(array_column(
            array_filter(
                $this->orderCardProvider->cardsFor($this->game, OrderCardSort::Urgency),
                static fn (array $card): bool => $card['turn'] === $currentTurn,
            ),
            'status',
        ));

        return [
            'pending' => $counts['pending'] ?? 0,
            'validated' => $counts['validated'] ?? 0,
            'missing' => $counts['missing'] ?? 0,
        ];
    }

    #[LiveAction]
    public function eraseOrder(#[LiveArg] string $playerId, #[LiveArg] int $turn): void
    {
        $player = $this->playerRepository->findById(Uuid::fromString($playerId));

        if (!$player instanceof Player || !$player->game->id->equals($this->game->id)) {
            return;
        }

        $windows = $this->shopConnector->windowsToErase($player, $turn);

        if ([] !== $windows) {
            $this->commandBus->dispatch(new EraseOrders($player->id, $windows));
        }
    }
}
