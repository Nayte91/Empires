<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Presentation\Component\Evolution;
use App\State\Game;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * The chronicle's chart had no test at all until #44, whose bug was invisible in the markup:
 * Chart.js's default 2:1 aspect ratio silently overrode the height the container's CSS reserves,
 * and the desktop layout only looked right because 640×320 happens to be that same ratio.
 *
 * Two of the three things pinned here are therefore option keys rather than DOM — that is where the
 * bug lived. The third is the one thing the rendered legend can get wrong on its own: a button whose
 * index no longer matches the dataset it toggles hides the wrong empire's curve, which no assertion
 * on the chart and none on the markup would otherwise notice.
 */
final class EvolutionTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    /**
     * Regression pin for #44. The canvas must take the height the stylesheet reserves instead of
     * deriving it from its own width, which is what left 131px of the container empty at 410px.
     */
    #[Test]
    public function theCanvasTakesItsHeightFromItsContainerRatherThanFromAFixedRatio(): void
    {
        $game = $this->createGameWithThreePlayers();

        $options = $this->mountEvolution($game)->getChart()->getOptions();

        $this->assertFalse($options['maintainAspectRatio']);
    }

    /**
     * The other half of #44: the legend is rendered as HTML beside the plot, because one drawn
     * inside the canvas can only take height away from the plot it sits in — and fourteen empires
     * is the ordinary case for this game, not the edge one.
     */
    #[Test]
    public function noLegendIsDrawnInsideTheCanvas(): void
    {
        $game = $this->createGameWithThreePlayers();

        $options = $this->mountEvolution($game)->getChart()->getOptions();

        $this->assertFalse($options['plugins']['legend']['display']);
    }

    /**
     * The HTML legend toggles a curve by its numeric index into the dataset array, so the order of
     * the buttons and the order of the datasets are one contract rather than two coincidences. A
     * legend listing the empires in any other order hides the wrong curve, silently, while every
     * other assertion in this file still passes.
     */
    #[Test]
    public function eachLegendButtonCarriesTheIndexOfTheDatasetItToggles(): void
    {
        $game = $this->createGameWithThreePlayers();

        $datasetLabels = array_column($this->mountEvolution($game)->getChart()->getData()['datasets'], 'label');
        $buttons = $this->renderTwigComponent('Evolution', ['game' => $game])
            ->crawler()
            ->filter('#evolution-legend > li > button[data-evolution-index-param]')
        ;

        $this->assertSame(['Alice', 'Bob', 'Carol'], $datasetLabels);
        $this->assertSame(['0', '1', '2'], $buttons->each(static fn (Crawler $button): ?string => $button->attr('data-evolution-index-param')));
        $this->assertSame($datasetLabels, $buttons->each(static fn (Crawler $button): string => trim($button->text())));
    }

    private function mountEvolution(Game $game): Evolution
    {
        $component = $this->mountTwigComponent('Evolution', ['game' => $game]);
        $this->assertInstanceOf(Evolution::class, $component);

        return $component;
    }

    /** Three empires, so an index that drifted by one has somewhere to drift to. */
    private function createGameWithThreePlayers(): Game
    {
        $game = GameBuilder::create()->withCurrentTurn(3)->persist($this->entityManager);

        foreach (['Alice' => 'minoa', 'Bob' => 'hellas', 'Carol' => 'assyria'] as $name => $empire) {
            PlayerBuilder::named($name)->in($game)->withEmpire($empire)->persist($this->entityManager);
        }

        return $game;
    }
}
