<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Action\ApplyStatAction;
use App\Rules\Action\SetStat;
use App\Rules\Action\Stat;
use App\Rules\Action\StatAction;
use App\Rules\HandSizeCalculator;
use App\Rules\StatBoundsCalculator;
use App\Rules\TaxCalculator;
use App\State\Player;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
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

    #[LiveProp(updateFromParent: true)]
    public int $value; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    /** Off inside a grid, where the column header already names the stat. */
    #[LiveProp]
    public bool $caption = true;

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly HandSizeCalculator $handSizeCalculator,
        private readonly StatBoundsCalculator $statBoundsCalculator,
        private readonly TaxCalculator $taxCalculator,
    ) {}

    public function mount(Player $player, string $stat, bool $caption = true): void
    {
        $this->player = $player;
        $this->stat = Stat::from($stat);
        $this->value = $this->stat->read($player);
        $this->caption = $caption;
    }

    /** @return list<StatAction> */
    public function getActions(): array
    {
        return array_values(array_filter(
            StatAction::forStat($this->stat),
            fn (StatAction $action): bool => $action->isOffered($this->player, $this->taxCalculator),
        ));
    }

    public function getFloor(): int
    {
        return $this->statBoundsCalculator->floorFor($this->player, $this->stat);
    }

    /** A stock holder is bounded by what its twin left. */
    public function getCeiling(): int
    {
        return $this->statBoundsCalculator->ceilingFor($this->player, $this->stat);
    }

    /** The last tile the grid draws; everything above {@see getCeiling()} is drawn disabled. */
    public function getDisplayCeiling(): int
    {
        return $this->statBoundsCalculator->displayCeilingFor($this->player, $this->stat);
    }

    public function isAvailable(StatAction $action): bool
    {
        return $action->isAvailable($this->player, $this->handSizeCalculator, $this->statBoundsCalculator, $this->taxCalculator);
    }

    /**
     * Every button inside the dialog commits the value it carries: one gesture, no confirmation
     * step.
     */
    #[LiveAction]
    public function pick(#[LiveArg] int $value): void
    {
        if ($this->stat->read($this->player) === $value) {
            return;
        }

        $this->commandBus->dispatch(new SetStat($this->player->id, $this->stat, $value));
        $this->value = $this->stat->read($this->player);
    }

    #[LiveAction]
    public function run(#[LiveArg] string $name): void
    {
        $action = StatAction::tryFrom($name);

        if (null === $action) {
            return;
        }

        $this->runAction($action);
    }

    /**
     * Only dispatches an action that belongs to this picker's own stat and is on the player's
     * menu (StatAction::forStat() + isOffered(), via getActions()) — the action name is a
     * client-supplied argument, so a foreign or unlisted action must never reach the bus. Whether
     * the action is actually available right now is the handler's call, not this guard's: the
     * handler is the one both a legitimate click and a crafted request go through alike.
     *
     * An action may move a stat this picker does not own — building a ship spends treasury — so
     * the neighbouring pickers refresh through the parent, not through this component's own value.
     */
    private function runAction(StatAction $action): void
    {
        if (!\in_array($action, $this->getActions(), true)) {
            return;
        }

        $this->commandBus->dispatch(new ApplyStatAction($this->player->id, $action));
        $this->value = $this->stat->read($this->player);
    }
}
