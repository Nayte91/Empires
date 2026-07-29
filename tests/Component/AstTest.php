<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Rules\Ruleset\AstEraDefinition;
use App\State\ASTVersion;
use App\State\Game;
use App\State\Player;
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
        $game = new Game();

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
        $game = new Game();
        new Player($game, 'Alice', 'minoa');

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();
        $tbody = $this->extractTag($rendered, 'tbody');

        $this->assertSame(17, substr_count($tbody, '<td')); // 16 track positions plus the score
        $this->assertStringContainsString('Alice', $rendered);
    }

    #[Test]
    public function playerAtPositionZeroGetsAMarkerTitledWithTheirNameAndEraName(): void
    {
        $game = new Game();
        new Player($game, 'Alice', 'minoa');

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        $this->assertStringContainsString('title="Alice — Start"', $rendered);
    }

    /**
     * The pawn is anchored on the first column the compact board keeps, not on the track's first
     * column, so a single offset places it correctly whether or not the opening stretch is shown.
     * Hence the offset is relative to that anchor and goes negative inside the opening stretch.
     */
    #[Test]
    public function markerCarriesItsOffsetFromTheAnchorColumnAsACssCustomPropertyForTransformAnimation(): void
    {
        $game = new Game();
        $player = new Player($game, 'Alice', 'minoa');
        $player->astPosition = 7;

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        $this->assertStringContainsString('--marker-pos: 2', $rendered); // position 7, anchored on column 5
        $this->assertStringContainsString('title="Alice — Early Bronze Age"', $rendered);
    }

    #[Test]
    public function aPlayerStillInsideTheOpeningStretchIsFlaggedOnTheirRowAndOffsetBackwards(): void
    {
        $game = new Game();
        $player = new Player($game, 'Alice', 'minoa');
        $player->astPosition = 4;

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        $this->assertStringContainsString('data-in-opening', $rendered);
        $this->assertStringContainsString('--marker-pos: -1', $rendered);
    }

    /**
     * Start and the Stone Age ask nothing of anybody, so the compact board drops them. The span is
     * read off the requirements rather than counted in, and the expert track opens the same way.
     */
    #[Test]
    public function theFiveOpeningColumnsAskingNoRequirementAreFlaggedInBothVersions(): void
    {
        $game = new Game();
        $player = new Player($game, 'Alice', 'minoa');
        $player->astPosition = 9;

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        $this->assertSame(5, substr_count($rendered, 'data-opening'));
        $this->assertSame(1, substr_count($rendered, 'data-anchor'));
        $this->assertStringNotContainsString('data-in-opening', $rendered);

        $game->astVersion = ASTVersion::EXPERT;
        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        $this->assertSame(5, substr_count($rendered, 'data-opening'));
    }

    /**
     * Canary for the board: the victory-point total ScoreCalculator computes reaches the last cell,
     * and the three leading scores wear the podium colours while an unscored player wears none.
     */
    #[Test]
    public function theScoreColumnClosesEachRowAndTheThreeLeadersWearGoldSilverAndBronze(): void
    {
        $game = new Game();
        $leader = new Player($game, 'Alice', 'minoa');
        $leader->ownAdvances(['advanced_military']); // 6 points
        $leader->cities = 5;                         // 11 in total
        $second = new Player($game, 'Bob', 'saba');
        $second->cities = 5;
        $third = new Player($game, 'Carl', 'assyria');
        $third->cities = 3;
        new Player($game, 'Dan', 'maurya');

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();
        $rows = new Crawler($rendered)->filter('tbody tr');

        $this->assertSame('11', trim($rows->eq(0)->filter('td:last-of-type')->text()));
        $this->assertSame('gold', $rows->eq(0)->filter('td:last-of-type')->attr('data-medal'));
        $this->assertSame('silver', $rows->eq(1)->filter('td:last-of-type')->attr('data-medal'));
        $this->assertSame('bronze', $rows->eq(2)->filter('td:last-of-type')->attr('data-medal'));
        $this->assertNull($rows->eq(3)->filter('td:last-of-type')->attr('data-medal'));
    }

    /** The board is read top-down as the standings, whatever the empires' order on the track. */
    #[Test]
    public function rowsAreOrderedByScoreWithTheLeaderOnTop(): void
    {
        $game = new Game();
        $trailing = new Player($game, 'Alice', 'minoa'); // minoa opens the empire list, so file order would put it first
        $trailing->cities = 1;
        $leading = new Player($game, 'Bob', 'egypt');
        $leading->cities = 9;

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();
        $rows = new Crawler($rendered)->filter('tbody tr');

        $this->assertSame('egypt', $rows->eq(0)->attr('data-empire'));
        $this->assertSame('minoa', $rows->eq(1)->attr('data-empire'));
    }

    #[Test]
    public function startColumnAlwaysRendersTheArrowRegardlessOfPlayerPosition(): void
    {
        // The marker is now a stable, always-rendered element in the start
        // column (moved purely via CSS transform for the transition to work),
        // so the arrow is always in the markup too — CSS (position + z-index)
        // is what visually covers it when a marker sits at position 0, not
        // Twig conditionally omitting it.
        $game = new Game();
        $atStart = new Player($game, 'Alice', 'minoa');
        $atStart->astPosition = 0;
        $elsewhere = new Player($game, 'Bob', 'saba');
        $elsewhere->astPosition = 3;

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();
        $tbody = $this->extractTag($rendered, 'tbody');

        $this->assertSame(2, substr_count($tbody, '→'));
    }

    #[Test]
    public function cellsMarkTheColumnMatchingTheCurrentTurnOncePerPlayerRowPlusTfoot(): void
    {
        $game = new Game();
        new Player($game, 'Alice', 'minoa');

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        $this->assertSame(2, substr_count($rendered, 'data-current-turn'));

        $game->currentTurn = 3;
        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        $this->assertSame(2, substr_count($rendered, 'data-current-turn'));
    }

    #[Test]
    public function rendersATfootWithVictoryPointsForEachPosition(): void
    {
        $game = new Game();

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
