<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DTO\AstEraDefinition;
use App\Entity\Game;
use App\Entity\Player;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class AstTest extends WebTestCase
{
    use InteractsWithTwigComponents;

    #[Test]
    public function rendersTheSevenEraHeadersSpanningTheFullTrackLength(): void
    {
        $game = new Game();

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        self::assertSame(7, substr_count($rendered, 'scope="col" colspan="'));
        self::assertStringContainsString('colspan="4"', $rendered); // Stone Age span
        self::assertStringContainsString('Start', $rendered);
        self::assertStringContainsString('Stone Age', $rendered);
        self::assertStringContainsString('Late Iron Age', $rendered);
    }

    #[Test]
    public function rendersTrackLengthCellsPerPlayerRow(): void
    {
        $game = new Game();
        new Player($game, 'Alice');

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();
        $tbody = $this->extractTag($rendered, 'tbody');

        self::assertSame(16, substr_count($tbody, '<td class="'));
        self::assertStringContainsString('Alice', $rendered);
    }

    #[Test]
    public function emptyStateColspanMatchesTrackLengthPlusOne(): void
    {
        $game = new Game();

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        self::assertStringContainsString('colspan="17"', $rendered);
        self::assertStringContainsString('No players yet.', $rendered);
    }

    #[Test]
    public function playerAtPositionZeroGetsAMarkerTitledWithTheirNameAndEraName(): void
    {
        $game = new Game();
        new Player($game, 'Alice');

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        self::assertStringContainsString('title="Alice — Start"', $rendered);
    }

    #[Test]
    public function startColumnShowsAnArrowWhenNotOccupiedByAPlayerMarker(): void
    {
        $game = new Game();
        $player = new Player($game, 'Alice');
        $player->astPosition = 3;

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();
        $tbody = $this->extractTag($rendered, 'tbody');

        self::assertStringContainsString('→', $tbody);
    }

    #[Test]
    public function startColumnHasNoArrowWhenOccupiedByAPlayerMarker(): void
    {
        $game = new Game();
        new Player($game, 'Alice');

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();
        $tbody = $this->extractTag($rendered, 'tbody');

        self::assertStringNotContainsString('→', $tbody);
    }

    #[Test]
    public function cellsMarkTheColumnMatchingTheCurrentTurnOncePerPlayerRowPlusTfoot(): void
    {
        $game = new Game();
        new Player($game, 'Alice');

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        self::assertSame(2, substr_count($rendered, 'data-current-turn'));

        $game->currentTurn = 3;
        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();

        self::assertSame(2, substr_count($rendered, 'data-current-turn'));
    }

    #[Test]
    public function rendersATfootWithVictoryPointsForEachPosition(): void
    {
        $game = new Game();

        $rendered = $this->renderTwigComponent('Ast', ['game' => $game])->toString();
        $tfoot = $this->extractTag($rendered, 'tfoot');

        self::assertSame(16, substr_count($tfoot, '<td'));
        self::assertStringContainsString('>0</td>', $tfoot); // position 0
        self::assertStringContainsString('>5</td>', $tfoot); // position 1
        self::assertStringContainsString('>75</td>', $tfoot); // position 15 (last)
        self::assertStringContainsString('>Victory Points</th>', $tfoot);
    }

    #[Test]
    public function requirementsMoleculeListsEachEraInBasicModeWithNoRequirementsFallback(): void
    {
        $eras = [
            new AstEraDefinition('stone_age', 'Stone Age', 5, [], [], 0),
            new AstEraDefinition('early_bronze_age', 'Early Bronze Age', 3, ['cities' => 2, 'min_advance_cost' => 1], ['cities' => 3], 1),
        ];

        $rendered = $this->renderTwigComponent('molecules:AstRequirements', ['eras' => $eras, 'astType' => 'basic'])->toString();

        self::assertStringContainsString('A.S.T. requirements (basic)', $rendered);
        self::assertStringContainsString('Stone Age:</strong> No requirements', $rendered);
        self::assertStringContainsString('Early Bronze Age:</strong> Cities: 2, Min advance cost: 1', $rendered);
    }

    #[Test]
    public function requirementsMoleculeSwitchesToExpertRequirementsWhenAstTypeIsExpert(): void
    {
        $eras = [
            new AstEraDefinition('early_bronze_age', 'Early Bronze Age', 3, ['cities' => 2], ['cities' => 3], 0),
        ];

        $rendered = $this->renderTwigComponent('molecules:AstRequirements', ['eras' => $eras, 'astType' => 'expert'])->toString();

        self::assertStringContainsString('A.S.T. requirements (expert)', $rendered);
        self::assertStringContainsString('Early Bronze Age:</strong> Cities: 3', $rendered);
    }

    private function extractTag(string $html, string $tag): string
    {
        $start = strpos($html, "<{$tag}>");
        $end = strpos($html, "</{$tag}>");

        self::assertNotFalse($start, "<{$tag}> not found in rendered output.");
        self::assertNotFalse($end, "</{$tag}> not found in rendered output.");

        return substr($html, $start, $end - $start);
    }
}
