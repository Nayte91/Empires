<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation;

use App\Presentation\CreationSummarizer;
use App\Rules\Action\CreateGame;
use App\Rules\HandSizeCalculator;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreationSummarizerTest extends TestCase
{
    #[Test]
    #[DataProvider('provideTheSummaryQuotesTheCardLimitForThePlayerCountCases')]
    public function theSummaryQuotesTheCardLimitForThePlayerCount(int $playerCount, string $expectedLine): void
    {
        $game = new CreateGame();
        $game->playerCount = $playerCount;

        $this->assertSame([$expectedLine], $this->summarizer()->summarize($game));
    }

    /** @return iterable<string, array{int, string}> */
    public static function provideTheSummaryQuotesTheCardLimitForThePlayerCountCases(): iterable
    {
        yield 'standard game' => [9, 'Card limit: 8'];

        yield 'large-game threshold' => [12, 'Card limit: 9'];
    }

    #[Test]
    public function aPlayerCountNoScenarioCoversStillGetsASummary(): void
    {
        $game = new CreateGame();
        $game->playerCount = 19;

        $this->assertSame(['Card limit: 9'], $this->summarizer()->summarize($game));
    }

    private function summarizer(): CreationSummarizer
    {
        return new CreationSummarizer(new HandSizeCalculator(GameConfig::gameRegistry(), GameConfig::advanceEffects()));
    }
}
