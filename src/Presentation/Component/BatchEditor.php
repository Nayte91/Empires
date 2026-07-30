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
use App\State\Game;
use App\State\Player;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: 'organisms/batchEditor.html.twig')]
final class BatchEditor
{
    use DefaultActionTrait;

    /** The two stats this modal may write — everything else on Player is read-only here. */
    private const array EDITABLE = [Stat::Census, Stat::Cities];

    #[LiveProp]
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    #[LiveProp]
    public bool $open = false;

    /** @var array<string, array<string, string>> keyed playerId => [statValue => string] */
    #[LiveProp(writable: true)]
    public array $values = [];

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly HandSizeCalculator $handSizeCalculator,
        private readonly StatBoundsCalculator $statBoundsCalculator,
        private readonly TaxCalculator $taxCalculator,
    ) {}

    #[LiveAction]
    public function openBatch(): void
    {
        $this->seedValues();
        $this->open = true;
    }

    #[LiveAction]
    public function closeBatch(): void
    {
        $this->open = false;
    }

    #[LiveAction]
    public function stepAst(#[LiveArg] string $playerId, #[LiveArg] int $delta): void
    {
        $player = $this->findPlayer($playerId);

        if (!$player instanceof Player) {
            return;
        }

        // No clamp, no availability check here: ApplyStatActionHandler already gates on
        // isOffered() then isAvailable(), and StatAction::apply() clamps. A second authority
        // in Presentation/ would only be able to drift from the one that actually decides.
        $this->commandBus->dispatch(new ApplyStatAction($player->id, $delta > 0 ? StatAction::AstForward : StatAction::AstBackward));
        $this->seedValues();
    }

    #[LiveAction]
    public function commit(#[LiveArg] string $playerId, #[LiveArg] string $stat): void
    {
        $player = $this->findPlayer($playerId);
        $target = Stat::tryFrom($stat);

        if (!$player instanceof Player || !\in_array($target, self::EDITABLE, true)) {
            return;
        }

        $raw = $this->values[$playerId][$stat] ?? '';

        // A cleared <input type="number"> posts "", and (int) '' === 0 would silently drive the
        // stat to its floor — so an empty field writes nothing.
        if ('' !== $raw) {
            $this->commandBus->dispatch(new SetStat($player->id, $target, (int) $raw));
        }

        // Unconditional, because both branches need it. SetStatHandler clamps, so a typed 42 on
        // cities must settle back to the stored 9; and a cleared field must redraw from the store
        // rather than stay blank, which an operator reads as data loss. The bus is synchronous,
        // so the entity is already current here.
        $this->seedValues();
    }

    public function floorFor(Player $player, Stat $stat): int
    {
        return $this->statBoundsCalculator->floorFor($player, $stat);
    }

    public function ceilingFor(Player $player, Stat $stat): int
    {
        return $this->statBoundsCalculator->ceilingFor($player, $stat);
    }

    public function canStepAst(Player $player, int $delta): bool
    {
        $action = $delta > 0 ? StatAction::AstForward : StatAction::AstBackward;

        return $action->isAvailable($player, $this->handSizeCalculator, $this->statBoundsCalculator, $this->taxCalculator);
    }

    /**
     * playerId is a client-writable LiveArg, so iterating the game's own roster — rather than
     * querying a repository — is what makes a player from another game structurally unreachable.
     * Same guard shape as StatPicker::runAction()'s in_array() check.
     */
    private function findPlayer(string $playerId): ?Player
    {
        foreach ($this->game->players as $player) {
            if ((string) $player->id === $playerId) {
                return $player;
            }
        }

        return null;
    }

    /**
     * The dot-path data-model binds into this array client-side, but only for keys that already
     * exist server-side — an unseeded key throws "Invalid model name" in the browser. So every
     * player/stat cell must be present before the dialog can render at all.
     */
    private function seedValues(): void
    {
        $this->values = [];

        foreach ($this->game->players as $player) {
            foreach (self::EDITABLE as $stat) {
                $this->values[(string) $player->id][$stat->value] = (string) $stat->read($player);
            }
        }
    }
}
