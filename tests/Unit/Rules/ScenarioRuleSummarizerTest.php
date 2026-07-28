<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules;

use App\Rules\Action\CreateGame;
use App\Rules\Scenario\HandLimitSummaryRule;
use App\Rules\HandSizeCalculator;
use App\Rules\Scenario\ScenarioRuleSummarizer;
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
        return new HandSizeCalculator(GameConfig::gameRegistry(), GameConfig::advanceEffects());
    }
}
