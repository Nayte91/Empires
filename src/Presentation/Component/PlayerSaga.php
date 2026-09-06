<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Action\Stat;
use App\Rules\PurchaseHistoryCalculator;
use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\ScoreCalculator;
use App\Rules\StandingsCalculator;
use App\State\Player;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(template: 'organisms/PlayerSaga.html.twig')]
final class PlayerSaga
{
    use AdvanceSectionsTrait;

    private const array COUNTER_STATS = [Stat::Cities, Stat::Ships, Stat::Census, Stat::Treasury, Stat::Cards];

    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    public function __construct(
        private readonly AdvanceRegistry $advanceRegistry,
        private readonly ScoreCalculator $scoreCalculator,
        private readonly StandingsCalculator $standingsCalculator,
        private readonly PurchaseHistoryCalculator $purchaseHistoryCalculator,
    ) {}

    /** @return list<array{turn: ?int, advances: list<Advance>}> */
    public function getAdvanceSections(): array
    {
        return $this->toAdvanceSections($this->player, $this->advanceRegistry, $this->purchaseHistoryCalculator);
    }

    public function getRank(): int
    {
        return $this->standingsCalculator->rankOf($this->player);
    }

    public function getRankSuffix(): string
    {
        return ltrim(new \NumberFormatter('en', \NumberFormatter::ORDINAL)->format($this->getRank()), '0123456789');
    }

    public function getMedal(): ?string
    {
        return $this->standingsCalculator->medalOf($this->player);
    }

    public function getScore(): int
    {
        return $this->standingsCalculator->scoreOf($this->player);
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

    /** @return list<array{key: string, label: string, value: string}> */
    public function getCounters(): array
    {
        return array_map(
            fn (Stat $stat): array => [
                'key' => $stat->value,
                'label' => $stat->label(),
                'value' => $stat->format($stat->read($this->player)),
            ],
            self::COUNTER_STATS,
        );
    }
}
