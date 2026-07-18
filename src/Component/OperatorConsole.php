<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Game\Dto\Advance;
use App\Repository\OrderRepository;
use App\Repository\PlayerRepository;
use App\Shop\Dto\Product;
use App\Shop\OrderStatus;
use App\Shop\Service\DirectSale;
use App\Shop\Service\PriceCalculator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: 'organisms/operatorConsole.html.twig')]
final class OperatorConsole
{
    use DefaultActionTrait;

    #[LiveProp]
    public GameSession $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp]
    public bool $creatingOrder = false;

    #[LiveProp(writable: true, onUpdated: 'onSelectedPlayerUpdated')]
    public string $selectedPlayerId = '';

    /** @var list<string> */
    #[LiveProp]
    public array $ticket = [];

    #[LiveProp(writable: true)]
    public int $money = 0;

    public ?string $error = null;

    /** @var ?list<Product> */
    private ?array $products = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderRepository $orderRepository,
        private readonly PlayerRepository $playerRepository,
        private readonly AdvanceCatalog $advanceCatalog,
        private readonly PriceCalculator $priceCalculator,
        private readonly DirectSale $directSale,
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
        $this->publish('turn-changed');
    }

    #[LiveAction]
    public function previousTurn(): void
    {
        if ($this->game->finished) {
            return;
        }

        --$this->game->currentTurn;
        $this->entityManager->flush();
        $this->publish('turn-changed');
    }

    #[LiveAction]
    public function finishGame(): void
    {
        if ($this->game->finished) {
            return;
        }

        $this->game->finishedAt = new \DateTimeImmutable();
        $this->entityManager->flush();
        $this->publish('game-finished');
    }

    #[LiveAction]
    public function adjustCities(#[LiveArg] string $playerId, #[LiveArg] int $delta): void
    {
        $player = $this->findGamePlayer($playerId);

        if (!$player instanceof Player) {
            return;
        }

        $player->cities += $delta;
        $this->entityManager->flush();
        $this->publish('player-updated');
    }

    #[LiveAction]
    public function adjustAstPosition(#[LiveArg] string $playerId, #[LiveArg] int $delta): void
    {
        $player = $this->findGamePlayer($playerId);

        if (!$player instanceof Player) {
            return;
        }

        $player->astPosition += $delta;
        $this->entityManager->flush();
        $this->publish('player-updated');
    }

    #[LiveAction]
    public function adjustCensus(#[LiveArg] string $playerId, #[LiveArg] int $delta): void
    {
        $player = $this->findGamePlayer($playerId);

        if (!$player instanceof Player) {
            return;
        }

        $player->census += $delta;
        $this->entityManager->flush();
        $this->publish('player-updated');
    }

    #[LiveAction]
    public function adjustTreasury(#[LiveArg] string $playerId, #[LiveArg] int $delta): void
    {
        $player = $this->findGamePlayer($playerId);

        if (!$player instanceof Player) {
            return;
        }

        $player->treasury += $delta;
        $this->entityManager->flush();
        $this->publish('player-updated');
    }

    #[LiveAction]
    public function startOrder(): void
    {
        if ($this->game->finished) {
            return;
        }

        $this->creatingOrder = true;
        $this->selectedPlayerId = '';
        $this->ticket = [];
        $this->money = 0;
        $this->error = null;
    }

    #[LiveAction]
    public function cancelOrder(): void
    {
        $this->creatingOrder = false;
        $this->selectedPlayerId = '';
        $this->ticket = [];
        $this->money = 0;
        $this->error = null;
    }

    /**
     * Preloads the ticket with an already-pending order's lines when its player
     * is selected, so re-opening a partially-built order resumes it on the same
     * Order row instead of creating a competing one (see DirectSale::sell()).
     */
    public function onSelectedPlayerUpdated(): void
    {
        $this->error = null;
        $this->money = 0;

        $player = $this->getSelectedPlayer();

        if (!$player instanceof Player) {
            $this->ticket = [];

            return;
        }

        $order = $this->orderRepository->findOneByPlayerAndTurn($player, $this->game->currentTurn);

        if ($order instanceof Order && OrderStatus::Pending === $order->status) {
            /** @var list<string> $lines */
            $lines = $order->lines;
            $this->ticket = $lines;

            return;
        }

        $this->ticket = [];
    }

    #[LiveAction]
    public function addToTicket(#[LiveArg] string $key): void
    {
        $player = $this->getSelectedPlayer();

        if (!$player instanceof Player) {
            $this->error = 'Select a player first.';

            return;
        }

        if (\in_array($key, $player->advances, true)) {
            $this->error = sprintf('Advance "%s" is already owned.', $key);

            return;
        }

        if (\in_array($key, $this->ticket, true)) {
            return;
        }

        $this->ticket[] = $key;
        $this->error = null;
    }

    #[LiveAction]
    public function removeFromTicket(#[LiveArg] string $key): void
    {
        $this->ticket = array_values(array_filter(
            $this->ticket,
            static fn (string $existing): bool => $existing !== $key,
        ));
    }

    #[LiveAction]
    public function checkout(): void
    {
        $player = $this->getSelectedPlayer();

        if (!$player instanceof Player) {
            $this->error = 'Select a player first.';

            return;
        }

        try {
            $this->directSale->sell($player, $this->ticket, $this->money);

            $this->creatingOrder = false;
            $this->selectedPlayerId = '';
            $this->ticket = [];
            $this->money = 0;
            $this->error = null;
        } catch (\DomainException $exception) {
            $this->error = $exception->getMessage();
        } catch (UniqueConstraintViolationException) {
            $this->error = 'Order already submitted, please re-select the player.';
        }
    }

    /** @return list<Order> */
    public function getOrders(): array
    {
        return $this->orderRepository->findByGameAndTurn($this->game, $this->game->currentTurn);
    }

    /** @return list<array{player: Player, locked: bool}> */
    public function getSelectablePlayers(): array
    {
        $entries = [];

        foreach ($this->game->players as $player) {
            $order = $this->orderRepository->findOneByPlayerAndTurn($player, $this->game->currentTurn);

            $entries[] = [
                'player' => $player,
                'locked' => $order instanceof Order && OrderStatus::Validated === $order->status,
            ];
        }

        return $entries;
    }

    public function getSelectedPlayer(): ?Player
    {
        if ('' === $this->selectedPlayerId) {
            return null;
        }

        return $this->findGamePlayer($this->selectedPlayerId);
    }

    /**
     * Catalogue minus the selected player's already-owned advances — a cashier
     * has no use for re-selling what a player already has.
     *
     * @return list<Product>
     */
    public function getProducts(): array
    {
        if (null !== $this->products) {
            return $this->products;
        }

        $player = $this->getSelectedPlayer();

        if (!$player instanceof Player) {
            return [];
        }

        /** @var list<Advance> $ownedAdvances */
        $ownedAdvances = $this->advanceCatalog->getAdvancesByNames($player->advances);

        $this->products = array_values(array_filter(array_map(
            fn (Advance $advance): ?Product => \in_array($advance->key, $player->advances, true)
                ? null
                : new Product(
                    advance: $advance,
                    netCost: $this->priceCalculator->netCost($advance, $ownedAdvances),
                    owned: false,
                    inCart: \in_array($advance->key, $this->ticket, true),
                ),
            $this->advanceCatalog->getAdvances(),
        )));

        return $this->products;
    }

    /** @return list<Product> */
    public function getTicketLines(): array
    {
        return Product::filterByKeys($this->getProducts(), $this->ticket);
    }

    public function getTicketTotal(): int
    {
        return array_sum(array_map(
            static fn (Product $product): int => $product->netCost,
            $this->getTicketLines(),
        ));
    }

    /** Finds a player and verifies it belongs to $this->game, so no operator can edit another game's player. */
    private function findGamePlayer(string $playerId): ?Player
    {
        $player = $this->playerRepository->find($playerId);

        if (!$player instanceof Player || $player->game->id->toRfc4122() !== $this->game->id->toRfc4122()) {
            return null;
        }

        return $player;
    }

    private function publish(string $event): void
    {
        $this->hub->publish(new Update(
            'empires/game/'.$this->game->id,
            sprintf('{"event":"%s"}', $event),
        ));
    }
}
