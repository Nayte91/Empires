<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Shop\ShopConnector;
use App\State\Order;
use App\State\Player;
use App\State\Repository\OrderRepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Userforged\ShopEngine\BuyerInterface;
use Userforged\ShopEngine\Command\EraseOrders;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Service\LineQuoter;

#[AsLiveComponent(template: 'organisms/PlayerOrders.html.twig')]
final class PlayerOrders
{
    use DefaultActionTrait;

    #[LiveProp]
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp(updateFromParent: true)]
    public string $ordersStamp; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AdvanceRegistry $advanceRegistry,
        private readonly LineQuoter $lineQuoter,
        private readonly MessageBusInterface $commandBus,
        private readonly ShopConnector $shopConnector,
    ) {}

    #[LiveAction]
    public function eraseOrder(#[LiveArg] int $turn): void
    {
        $windows = $this->shopConnector->windowsToErase($this->player, $turn);

        if ([] !== $windows) {
            $this->commandBus->dispatch(new EraseOrders($this->player->id, $windows));
        }
    }

    #[LiveListener('orderPlaced')]
    public function onOrderPlaced(): void {}

    #[LiveListener('cartChanged')]
    public function onCartChanged(): void {}

    /** @return list<array{turn: int, status: string, slugs: list<string>, total: int, vp: int}> */
    public function getCards(): array
    {
        $byTurn = [];

        foreach ($this->orderRepository->findByPlayer($this->player) as $order) {
            $byTurn[$order->turn] = $order;
        }

        $buyer = $this->shopConnector->buyerFor($this->player);

        $cards = [];

        for ($turn = $this->player->game->currentTurn; $turn >= 1; --$turn) {
            $cards[] = $this->summarizeTurn($turn, $byTurn[$turn] ?? null, $buyer);
        }

        return $cards;
    }

    /** @return array{turn: int, status: string, slugs: list<string>, total: int, vp: int} */
    private function summarizeTurn(int $turn, ?Order $order, BuyerInterface $buyer): array
    {
        $slugs = $order?->keys() ?? [];

        /** @var list<Advance> $advances */
        $advances = $this->advanceRegistry->getAdvancesByNames($slugs);

        $total = OrderStatus::Validated === $order?->status
            ? $order->total ?? 0
            : array_sum(array_map(
                static fn (OrderLine $line): int => $line->netCost,
                $this->lineQuoter->quote($order instanceof Order ? $this->lineQuoter->intentsFromLines($order->lines()) : [], $buyer),
            ));

        return [
            'turn' => $turn,
            'status' => match (true) {
                $order instanceof Order => $order->status->value,
                $turn === $this->player->game->currentTurn => 'missing',
                default => 'empty',
            },
            'slugs' => $slugs,
            'total' => $total,
            'vp' => array_sum(array_map(static fn (Advance $advance): int => $advance->points, $advances)),
        ];
    }
}
