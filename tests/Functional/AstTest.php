<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Dto\AstEraDefinition;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class AstTest extends WebTestCase
{
    use InteractsWithTwigComponents;

    #[Test]
    public function rendersTheSevenEraHeadersSpanningTheFullTrackLength(): void
    {
        $game = new GameSession();

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
        $game = new GameSession();
        $player = new Player($game, 'Alice');
        $player->empire = 'minoa';

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();
        $tbody = $this->extractTag($rendered, 'tbody');

        $this->assertSame(16, substr_count($tbody, '<td'));
        $this->assertStringContainsString('Alice', $rendered);
    }

    #[Test]
    public function emptyStateColspanMatchesTrackLengthPlusOne(): void
    {
        $game = new GameSession();

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        $this->assertStringContainsString('colspan="17"', $rendered);
        $this->assertStringContainsString('No players yet.', $rendered);
    }

    #[Test]
    public function playerAtPositionZeroGetsAMarkerTitledWithTheirNameAndEraName(): void
    {
        $game = new GameSession();
        $player = new Player($game, 'Alice');
        $player->empire = 'minoa';

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        $this->assertStringContainsString('title="Alice — Start"', $rendered);
    }

    #[Test]
    public function markerCarriesThePlayersAstPositionAsACssCustomPropertyForTransformAnimation(): void
    {
        $game = new GameSession();
        $player = new Player($game, 'Alice');
        $player->astPosition = 4;
        $player->empire = 'minoa';

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        $this->assertStringContainsString('--marker-pos: 4', $rendered);
        $this->assertStringContainsString('title="Alice — Stone Age"', $rendered);
    }

    #[Test]
    public function startColumnAlwaysRendersTheArrowRegardlessOfPlayerPosition(): void
    {
        // The marker is now a stable, always-rendered element in the start
        // column (moved purely via CSS transform for the transition to work),
        // so the arrow is always in the markup too — CSS (position + z-index)
        // is what visually covers it when a marker sits at position 0, not
        // Twig conditionally omitting it.
        $game = new GameSession();
        $atStart = new Player($game, 'Alice');
        $atStart->astPosition = 0;
        $atStart->empire = 'minoa';
        $elsewhere = new Player($game, 'Bob');
        $elsewhere->astPosition = 3;
        $elsewhere->empire = 'saba';

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();
        $tbody = $this->extractTag($rendered, 'tbody');

        $this->assertSame(2, substr_count($tbody, '→'));
    }

    #[Test]
    public function cellsMarkTheColumnMatchingTheCurrentTurnOncePerPlayerRowPlusTfoot(): void
    {
        $game = new GameSession();
        $player = new Player($game, 'Alice');
        $player->empire = 'minoa';

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        $this->assertSame(2, substr_count($rendered, 'data-current-turn'));

        $game->currentTurn = 3;
        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        $this->assertSame(2, substr_count($rendered, 'data-current-turn'));
    }

    #[Test]
    public function rendersATfootWithVictoryPointsForEachPosition(): void
    {
        $game = new GameSession();

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();
        $tfoot = $this->extractTag($rendered, 'tfoot');

        $this->assertSame(16, substr_count($tfoot, '<td'));
        $this->assertStringContainsString('>0</td>', $tfoot); // position 0
        $this->assertStringContainsString('>5</td>', $tfoot); // position 1
        $this->assertStringContainsString('>75</td>', $tfoot); // position 15 (last)
        $this->assertStringContainsString('>Victory Points</th>', $tfoot);
    }

    #[Test]
    public function requirementsMoleculeListsEachEraInBasicVersionWithNoRequirementsFallback(): void
    {
        $eras = [
            new AstEraDefinition('stone_age', 'Stone Age', 5, [], [], 0),
            new AstEraDefinition('early_bronze_age', 'Early Bronze Age', 3, ['cities' => 2, 'min_advance_cost' => 1], ['cities' => 3], 1),
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
            new AstEraDefinition('early_bronze_age', 'Early Bronze Age', 3, ['cities' => 2], ['cities' => 3], 0),
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
