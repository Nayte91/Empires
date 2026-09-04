<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Presentation\Component\Evolution;
use App\State\Game;
use App\Tests\Support\Fixture\Tables;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * A legend button whose index does not match its dataset hides the wrong empire's curve, which no
 * other assertion notices.
 */
final class EvolutionTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    #[Test]
    public function eachLegendButtonCarriesTheIndexOfTheDatasetItToggles(): void
    {
        $game = Tables::westTable($this->entityManager);

        $datasetLabels = array_column($this->mountEvolution($game)->getChart()->getData()['datasets'], 'label');
        $buttons = $this->renderTwigComponent('Evolution', ['game' => $game])
            ->crawler()
            ->filter('#evolution-legend > li > button[data-evolution-index-param]')
        ;

        $this->assertSame(['Alice', 'Bob', 'Carol', 'Dave', 'Eve'], $datasetLabels);
        $this->assertSame(['0', '1', '2', '3', '4'], $buttons->each(static fn (Crawler $button): ?string => $button->attr('data-evolution-index-param')));
        $this->assertSame($datasetLabels, $buttons->each(static fn (Crawler $button): string => trim($button->text())));
    }

    private function mountEvolution(Game $game): Evolution
    {
        $component = $this->mountTwigComponent('Evolution', ['game' => $game]);
        $this->assertInstanceOf(Evolution::class, $component);

        return $component;
    }
}
