<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\PurchaseHistoryCalculator;
use App\Rules\Ruleset\EmpireRegistry;
use App\State\Player;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A line chart of what one player spent, turn by turn, from the first turn the shop opens — static,
 * since a finished game never Mercure-refreshes. Single-series by nature (one player, one basket
 * per turn), so unlike {@see Evolution} it draws no legend; the average since that first turn runs
 * across it as a dashed line in the empire's colour, the whole table's in the game's blue, and both
 * are spelled out under the plot.
 */
#[AsTwigComponent(template: 'molecules/Purchases.html.twig')]
final class Purchases
{
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    public function __construct(
        private readonly PurchaseHistoryCalculator $purchaseHistoryCalculator,
        private readonly EmpireRegistry $empireRegistry,
        private readonly ChartBuilderInterface $chartBuilder,
    ) {}

    /** A player who never spent anything gets a sentence, not a flat line on a made-up axis. */
    public function hasPurchases(): bool
    {
        return [] !== array_filter($this->totals(), static fn (int $total): bool => $total > 0);
    }

    public function getChart(): Chart
    {
        $totals = $this->totals();
        $average = $this->getAverage();
        $color = $this->empireRegistry->findByName($this->player->empire)->color ?? 'dimgray';

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);

        $chart->setData([
            'labels' => range(PurchaseHistoryCalculator::AVERAGE_FROM_TURN, $this->player->game->currentTurn),
            'datasets' => [
                [
                    'label' => 'Purchases',
                    'data' => $totals,
                    'borderColor' => $color,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Average',
                    'data' => array_fill(0, \count($totals), $average),
                    'borderColor' => $color,
                    'borderDash' => [6, 4],
                    'borderWidth' => 1,
                    'pointRadius' => 0,
                ],
                [
                    'label' => 'Table average',
                    'data' => array_fill(0, \count($totals), $this->getTableAverage()),
                    // The game's own blue (--operator-accent), a colour no empire wears.
                    'borderColor' => 'royalblue',
                    'borderDash' => [6, 4],
                    'borderWidth' => 1,
                    'pointRadius' => 0,
                ],
            ],
        ]);

        $chart->setOptions([
            // The container's CSS owns the plot's shape (see purchases.css), same fix as
            // Evolution: the canvas must fill whatever height that CSS reserves instead of
            // deriving it from a fixed ratio.
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['display' => false]],
            'scales' => [
                'x' => ['title' => ['display' => true, 'text' => 'Turn']],
                // Spend is a whole number that starts at nothing: no negative half of the axis, no decimals.
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ]);

        return $chart;
    }

    /** Null when the game never reached {@see PurchaseHistoryCalculator::AVERAGE_FROM_TURN} — nothing to average. */
    public function getAverage(): ?float
    {
        return $this->purchaseHistoryCalculator->averageFromTurnSix($this->player);
    }

    /** The whole table's, to read one's own against. */
    public function getTableAverage(): ?float
    {
        return $this->purchaseHistoryCalculator->tableAverageFromTurnSix($this->player->game);
    }

    /** @return list<int> one total per turn from the first the shop opens, the earlier ones being unbuyable */
    private function totals(): array
    {
        return \array_slice($this->purchaseHistoryCalculator->totalsPerTurn($this->player), PurchaseHistoryCalculator::AVERAGE_FROM_TURN - 1);
    }
}
