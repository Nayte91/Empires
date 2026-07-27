<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Service;

use App\Game\Command\CreateGame;
use App\Game\Scenario\HandLimitSummaryRule;
use App\Game\Service\HandSizeCalculator;
use App\Game\Service\ScenarioRuleSummarizer;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScenarioRuleSummarizerTest extends TestCase
{
    #[Test]
    public function summarizeReturnsTheDescriptionOfEachRule(): void
    {
        $game = new CreateGame();

        $summarizer = new ScenarioRuleSummarizer([new HandLimitSummaryRule($this->hand())]);

        $this->assertSame(['Card limit: 8'], $summarizer->summarize($game));
    }

    private function hand(): HandSizeCalculator
    {
        return new HandSizeCalculator(GameConfig::gameData(), GameConfig::advanceEffects());
    }
}
