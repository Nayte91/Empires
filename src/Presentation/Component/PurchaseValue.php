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
 * A line chart of what one player spent, turn by turn — static, since a finished game never
 * Mercure-refreshes. Single-series by nature (one player, one basket per turn), so unlike
 * {@see Evolution} it draws no legend: there is only one line to read, already named by the
 * chart title.
 */
#[AsTwigComponent(template: 'molecules/purchaseValue.html.twig')]
final class PurchaseValue
{
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    public function __construct(
        private readonly PurchaseHistoryCalculator $purchaseHistoryCalculator,
        private readonly EmpireRegistry $empireRegistry,
        private readonly ChartBuilderInterface $chartBuilder,
    ) {}

    public function getChart(): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);

        $chart->setData([
            'labels' => range(1, $this->player->game->currentTurn),
            'datasets' => [[
                'label' => 'Purchase value',
                'data' => $this->purchaseHistoryCalculator->totalsPerTurn($this->player),
                'borderColor' => $this->empireRegistry->findByName($this->player->empire)->color ?? 'dimgray',
                'tension' => 0.3,
            ]],
        ]);

        $chart->setOptions([
            // The container's CSS owns the plot's shape (see purchaseValue.css), same fix as
            // Evolution: the canvas must fill whatever height that CSS reserves instead of
            // deriving it from a fixed ratio.
            'maintainAspectRatio' => false,
            'plugins' => [
                'title' => ['display' => true, 'text' => 'Purchase value over turns'],
                'legend' => ['display' => false],
            ],
            'scales' => [
                'x' => ['title' => ['display' => true, 'text' => 'Turn']],
            ],
        ]);

        return $chart;
    }

    /** Null when the game never reached {@see PurchaseHistoryCalculator::AVERAGE_FROM_TURN} — nothing to average. */
    public function getAverage(): ?float
    {
        return $this->purchaseHistoryCalculator->averageFromTurnSix($this->player);
    }
}
