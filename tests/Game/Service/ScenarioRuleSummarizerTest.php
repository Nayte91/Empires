<?php

declare(strict_types=1);

namespace App\Tests\Game\Service;

use App\Game\Advisory\HandLimitRule;
use App\Game\Command\CreateGame;
use App\Game\Scenario\HandLimitSummaryRule;
use App\Game\Service\ScenarioRuleSummarizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScenarioRuleSummarizerTest extends TestCase
{
    #[Test]
    public function summarizeReturnsTheDescriptionOfEachRule(): void
    {
        $game = new CreateGame();

        $summarizer = new ScenarioRuleSummarizer([new HandLimitSummaryRule(new HandLimitRule())]);

        $this->assertSame(['Card limit: 8'], $summarizer->summarize($game));
    }
}
