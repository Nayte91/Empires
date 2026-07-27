<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Player;
use App\Game\Service\HandSizeCalculator;
use App\Game\Service\StockCalculator;
use App\Game\Service\TaxCalculator;
use App\Game\Stat;
use App\Game\StatAction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(name: 'molecules:StatPicker', template: 'molecules/StatPicker.html.twig')]
final class StatPicker
{
    use DefaultActionTrait;

    #[LiveProp]
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp]
    public Stat $stat; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp(writable: true, updateFromParent: true)]
    public int $value; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp(writable: true)]
    public ?string $pendingAction = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HubInterface $hub,
        private readonly HandSizeCalculator $handSizeCalculator,
        private readonly StockCalculator $stockCalculator,
        private readonly TaxCalculator $taxCalculator,
    ) {}

    public function mount(Player $player, string $stat): void
    {
        $this->player = $player;
        $this->stat = Stat::from($stat);
        $this->value = $this->stat->read($player);
    }

    /** @return list<StatAction> */
    public function getActions(): array
    {
        return array_values(array_filter(
            StatAction::forStat($this->stat),
            fn (StatAction $action): bool => $action->isOffered($this->player, $this->taxCalculator),
        ));
    }

    /** The highest value the grid may offer — a stock holder is bounded by what its twin left. */
    public function getCeiling(): int
    {
        return $this->stockCalculator->ceilingFor($this->player, $this->stat);
    }

    public function isAvailable(StatAction $action): bool
    {
        return $action->isAvailable($this->player, $this->handSizeCalculator, $this->stockCalculator, $this->taxCalculator);
    }

    #[LiveAction]
    public function save(): void
    {
        $action = StatAction::tryFrom($this->pendingAction ?? '');
        $this->pendingAction = null;

        if (null !== $action) {
            $this->runAction($action);

            return;
        }

        $current = $this->stat->read($this->player);

        if ($current === $this->value) {
            return;
        }

        $this->stat->write($this->player, min($this->value, $this->getCeiling()));
        $this->value = $this->stat->read($this->player);

        $this->entityManager->flush();
        $this->publish('player-updated');
    }

    /**
     * An action may move a stat this picker does not own — building a ship spends treasury — so
     * the neighbouring pickers refresh through the parent, not through this component's own value.
     */
    private function runAction(StatAction $action): void
    {
        if (!\in_array($action, $this->getActions(), true)) {
            return;
        }

        $action->apply($this->player, $this->handSizeCalculator, $this->stockCalculator, $this->taxCalculator);
        $this->value = $this->stat->read($this->player);

        $this->entityManager->flush();
        $this->publish('player-updated');
    }

    private function publish(string $event): void
    {
        $this->hub->publish(new Update(
            'empires/game/'.$this->player->game->id,
            sprintf('{"event":"%s"}', $event),
        ));
    }
}
