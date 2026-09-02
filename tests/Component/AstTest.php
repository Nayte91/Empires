<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Rules\Ruleset\AstEraDefinition;
use App\State\ASTVersion;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class AstTest extends WebTestCase
{
    use InteractsWithTwigComponents;

    #[Test]
    public function rendersTheSevenEraHeadersSpanningTheFullTrackLength(): void
    {
        $game = GameBuilder::create()->build();

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        $this->assertSame(7, substr_count($rendered, 'scope="col" colspan="'));
        $this->assertStringContainsString('colspan="4"', $rendered); // Stone Age span
        $this->assertStringContainsString('Start', $rendered);
        $this->assertStringContainsString('Stone Age', $rendered);
        $this->assertStringContainsString('Late Iron Age', $rendered);
    }

    #[Test]
    public function rendersTrackLengthCellsPerPlayerRow(): void
    {
        $game = GameBuilder::create()->build();
        PlayerBuilder::named('Alice')->in($game)->build();

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();
        $tbody = $this->extractTag($rendered, 'tbody');

        $this->assertSame(17, substr_count($tbody, '<td')); // 16 track positions plus the score
        $this->assertStringContainsString('Alice', $rendered);
    }

    #[Test]
    public function playerAtPositionZeroGetsAMarkerTitledWithTheirNameAndEraName(): void
    {
        $game = GameBuilder::create()->build();
        PlayerBuilder::named('Alice')->in($game)->build();

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        $this->assertStringContainsString('title="Alice — Start"', $rendered);
    }

    #[Test]
    public function theMarkerIsOffsetFromTheAnchorByThePositionItStandsOn(): void
    {
        $game = GameBuilder::create()->build();
        PlayerBuilder::named('Alice')->in($game)->withAstPosition(7)->build();

        $crawler = new Crawler($this->renderTwigComponent('Ast', ['game' => $game])->toString());
        $cells = $crawler->filter('tbody tr td');
        $anchor = array_keys(array_filter($cells->each(static fn (Crawler $cell): bool => null !== $cell->attr('data-anchor'))))[0];
        $marker = $crawler->filter('tbody tr .marker');

        $this->assertCount(1, $marker, 'One pawn per row, wherever the player stands.');
        $this->assertNotNull($marker->closest('td')?->attr('data-anchor'), 'It is parked on the anchor column.');
        $this->assertStringContainsString('--marker-pos: '.(7 - $anchor), (string) $marker->attr('style'));
        $this->assertStringContainsString('Alice — Early Bronze Age', (string) $marker->attr('title'));
    }

    #[Test]
    public function aPlayerStillInsideTheOpeningStretchStandsLeftOfTheAnchor(): void
    {
        $game = GameBuilder::create()->build();
        PlayerBuilder::named('Alice')->in($game)->withAstPosition(0)->build();

        $crawler = new Crawler($this->renderTwigComponent('Ast', ['game' => $game])->toString());
        $marker = $crawler->filter('tbody tr .marker');

        $this->assertCount(1, $marker);
        $this->assertMatchesRegularExpression('/--marker-pos: -\d+/', (string) $marker->attr('style'));
    }

    #[Test]
    public function theFiveOpeningColumnsAskingNoRequirementAreFlaggedInBothVersions(): void
    {
        $game = GameBuilder::create()->build();
        PlayerBuilder::named('Alice')->in($game)->withAstPosition(9)->build();

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        $this->assertSame(5, substr_count($rendered, 'data-opening'));
        $this->assertSame(1, substr_count($rendered, 'data-anchor'));

        $game->astVersion = ASTVersion::EXPERT;
        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        $this->assertSame(5, substr_count($rendered, 'data-opening'));
    }

    #[Test]
    public function theScoreColumnClosesEachRowAndTheThreeLeadersWearGoldSilverAndBronze(): void
    {
        $game = GameBuilder::create()->build();
        PlayerBuilder::named('Alice')->in($game)->withAdvances(['advanced_military'])->withCities(5)->build(); // 6 points + 5 cities = 11 in total
        PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->withCities(5)->build();
        PlayerBuilder::named('Carl')->in($game)->withEmpire('assyria')->withCities(3)->build();
        PlayerBuilder::named('Dan')->in($game)->withEmpire('maurya')->build();

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();
        $rows = new Crawler($rendered)->filter('tbody tr');

        $this->assertSame('11', trim($rows->eq(0)->filter('td:last-of-type')->text()));
        $this->assertSame('gold', $rows->eq(0)->filter('td:last-of-type')->attr('data-medal'));
        $this->assertSame('silver', $rows->eq(1)->filter('td:last-of-type')->attr('data-medal'));
        $this->assertSame('bronze', $rows->eq(2)->filter('td:last-of-type')->attr('data-medal'));
        $this->assertNull($rows->eq(3)->filter('td:last-of-type')->attr('data-medal'));
    }

    #[Test]
    public function rowsAreOrderedByScoreWithTheLeaderOnTop(): void
    {
        $game = GameBuilder::create()->build();
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withCities(1)->build(); // minoa opens the empire list, so file order would put it first
        PlayerBuilder::named('Bob')->in($game)->withEmpire('egypt')->withCities(9)->build();

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();
        $rows = new Crawler($rendered)->filter('tbody tr');

        $this->assertSame('egypt', $rows->eq(0)->attr('data-empire'));
        $this->assertSame('minoa', $rows->eq(1)->attr('data-empire'));
    }

    #[Test]
    public function startColumnAlwaysRendersTheArrowRegardlessOfPlayerPosition(): void
    {
        $game = GameBuilder::create()->build();
        PlayerBuilder::named('Alice')->in($game)->withAstPosition(0)->build();
        PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->withAstPosition(3)->build();

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();
        $tbody = $this->extractTag($rendered, 'tbody');

        $this->assertSame(2, substr_count($tbody, '→'));
    }

    #[Test]
    public function cellsMarkTheColumnMatchingTheCurrentTurnOncePerPlayerRowPlusTfoot(): void
    {
        $game = GameBuilder::create()->build();
        PlayerBuilder::named('Alice')->in($game)->build();

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        $this->assertSame(2, substr_count($rendered, 'data-current-turn'));

        $game->currentTurn = 3;
        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        $this->assertSame(2, substr_count($rendered, 'data-current-turn'));
    }

    #[Test]
    public function rendersATfootWithVictoryPointsForEachPosition(): void
    {
        $game = GameBuilder::create()->build();

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();
        $tfoot = $this->extractTag($rendered, 'tfoot');

        $this->assertSame(17, substr_count($tfoot, '<td')); // 16 track positions plus the empty score cell
        $this->assertStringContainsString('>0</td>', $tfoot); // position 0
        $this->assertStringContainsString('>5</td>', $tfoot); // position 1
        $this->assertStringContainsString('>75</td>', $tfoot); // position 15 (last)
        $this->assertStringContainsString('>Victory Points</th>', $tfoot);
    }

    #[Test]
    public function requirementsMoleculeListsEachEraInBasicVersionWithNoRequirementsFallback(): void
    {
        $eras = [
            new AstEraDefinition('stone_age', 'Stone Age', [], [], 0),
            new AstEraDefinition('early_bronze_age', 'Early Bronze Age', ['cities' => 2, 'min_advance_cost' => 1], ['cities' => 3], 1),
        ];

        $rendered = $this->renderTwigComponent('molecules:AstRequirements', ['eras' => $eras, 'astVersion' => 'basic'])->toString();

        $this->assertStringContainsString('A.S.T. requirements (basic)', $rendered);
        $this->assertStringContainsString('Stone Age:</strong> No requirements', $rendered);
        $this->assertStringContainsString('Early Bronze Age:</strong> Cities: 2, Min advance cost: 1', $rendered);
    }

    #[Test]
    public function requirementsMoleculeSwitchesToExpertRequirementsWhenAstVersionIsExpert(): void
    {
        $eras = [
            new AstEraDefinition('early_bronze_age', 'Early Bronze Age', ['cities' => 2], ['cities' => 3], 0),
        ];

        $rendered = $this->renderTwigComponent('molecules:AstRequirements', ['eras' => $eras, 'astVersion' => 'expert'])->toString();

        $this->assertStringContainsString('A.S.T. requirements (expert)', $rendered);
        $this->assertStringContainsString('Early Bronze Age:</strong> Cities: 3', $rendered);
    }

    private function extractTag(string $html, string $tag): string
    {
        $start = strpos($html, "<{$tag}>");
        $end = strpos($html, "</{$tag}>");

        $this->assertNotFalse($start, "<{$tag}> not found in rendered output.");
        $this->assertNotFalse($end, "</{$tag}> not found in rendered output.");

        return substr($html, $start, $end - $start);
    }
}
