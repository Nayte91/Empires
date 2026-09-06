<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\Empire;
use App\Rules\Ruleset\EmpireRegistry;
use App\Rules\ScoreHistoryCalculator;
use App\Rules\StandingsCalculator;
use App\State\Game;
use App\State\Player;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(template: 'molecules/Evolution.html.twig')]
final class Evolution
{
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    public function __construct(
        private readonly ScoreHistoryCalculator $scoreHistoryCalculator,
        private readonly EmpireRegistry $empireRegistry,
        private readonly ChartBuilderInterface $chartBuilder,
        private readonly StandingsCalculator $standingsCalculator,
    ) {}

    /** @return list<Player> */
    public function getPlayers(): array
    {
        return $this->standingsCalculator->standings($this->game);
    }

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
                static fn (Player $player): array => [
                    'label' => $player->name,
                    'data' => $series[$player->slug],
                    'borderColor' => $empireColors[$player->empire] ?? 'dimgray',
                    'tension' => 0.3,
                ],
                $this->getPlayers(),
            ),
        ]);

        $chart->setOptions([
            'maintainAspectRatio' => false,
            'plugins' => [
                'title' => ['display' => true, 'text' => 'Victory points over turns'],
                'legend' => ['display' => false],
            ],
            'scales' => [
                'x' => ['title' => ['display' => true, 'text' => 'Turn']],
            ],
        ]);

        return $chart;
    }
}
