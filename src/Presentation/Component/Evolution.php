<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\Empire;
use App\Rules\Ruleset\EmpireRegistry;
use App\Rules\ScoreHistoryCalculator;
use App\State\Game;
use App\State\Player;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A line chart of how each player's score grew, turn by turn — static, since a finished game
 * never Mercure-refreshes.
 */
#[AsTwigComponent(template: 'molecules/Evolution.html.twig')]
final class Evolution
{
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    public function __construct(
        private readonly ScoreHistoryCalculator $scoreHistoryCalculator,
        private readonly EmpireRegistry $empireRegistry,
        private readonly ChartBuilderInterface $chartBuilder,
    ) {}

    public function getChart(): Chart
    {
        $series = $this->scoreHistoryCalculator->pointsPerTurn($this->game);
        $empireColors = array_map(
            static fn (Empire $empire): string => $empire->color,
            $this->empireRegistry->findAll(),
        );

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);

        $chart->setData([
            'labels' => range(1, $this->game->currentTurn),
            'datasets' => array_map(
                // Reconstructed up to the last turn, which is the player's real score — the graph
                // and the A.S.T. board's SCORE column agree there (see ScoreHistoryCalculator).
                static fn (Player $player): array => [
                    'label' => $player->name,
                    'data' => $series[$player->slug],
                    'borderColor' => $empireColors[$player->empire] ?? 'dimgray',
                    'tension' => 0.3,
                ],
                $this->game->players->toArray(),
            ),
        ]);

        $chart->setOptions([
            // The container's CSS now owns the plot's shape (see evolution.css); the canvas must
            // fill whatever height that CSS reserves instead of deriving it from a fixed ratio.
            'maintainAspectRatio' => false,
            'plugins' => [
                'title' => ['display' => true, 'text' => 'Victory points over turns'],
                // Rendered as HTML in evolution.html.twig instead — a legend drawn inside the canvas
                // can only take height away from the plot, never grow with the content it needs.
                'legend' => ['display' => false],
            ],
            'scales' => [
                'x' => ['title' => ['display' => true, 'text' => 'Turn']],
            ],
        ]);

        return $chart;
    }
}
