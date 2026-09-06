<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\PurchaseHistoryCalculator;
use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\ScoreCalculator;
use App\State\Player;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: 'organisms/PlayerBoard.html.twig')]
final class PlayerBoard
{
    use AdvanceSectionsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(
        private readonly AdvanceRegistry $advanceRegistry,
        private readonly ScoreCalculator $scoreCalculator,
        private readonly PurchaseHistoryCalculator $purchaseHistoryCalculator,
    ) {}

    /** @return list<array{turn: ?int, advances: list<Advance>}> */
    public function getAdvanceSections(): array
    {
        return $this->toAdvanceSections($this->player, $this->advanceRegistry, $this->purchaseHistoryCalculator);
    }

    /** @return list<Advance> */
    public function getOwnedAdvances(): array
    {
        return array_values($this->advanceRegistry->getAdvancesByNames($this->player->advances));
    }

    public function getAdvancePoints(): int
    {
        return $this->scoreCalculator->advancePointsFor($this->getOwnedAdvances());
    }
}
