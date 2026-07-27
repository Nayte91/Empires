<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Advisory\CitySupportRule;
use App\Game\Advisory\HandLimitRule;
use App\Game\Advisory\TaxPaymentRule;
use App\Game\Service\CitySupportCalculator;
use App\Game\Service\HandSizeCalculator;
use App\Game\Service\PlayerAdvisor;
use App\Game\Service\StockCalculator;
use App\Game\Service\TaxCalculator;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlayerAdvisorTest extends TestCase
{
    #[Test]
    public function wellSupportedPlayerGetsNoAdvisories(): void
    {
        $player = new Player(new GameSession(), 'Bob');
        $player->cities = 2;
        $player->census = 10;

        $advisor = new PlayerAdvisor([new CitySupportRule(new CitySupportCalculator()), new TaxPaymentRule($this->tax()), new HandLimitRule($this->hand())]);

        $this->assertSame([], $advisor->advisoriesFor($player));
    }

    #[Test]
    public function troubledPlayerGetsAllAdvisoriesInPriorityOrder(): void
    {
        $player = new Player(new GameSession(), 'Bob');
        $player->cities = 5;
        $player->census = 1;
        $player->treasury = 50;
        $player->cards = 9;

        $advisor = new PlayerAdvisor([new CitySupportRule(new CitySupportCalculator()), new TaxPaymentRule($this->tax()), new HandLimitRule($this->hand())]);
        $advisories = $advisor->advisoriesFor($player);

        $this->assertCount(3, $advisories);
        $this->assertSame("You can't support your cities!", $advisories[0]->message);
        $this->assertSame("You can't pay your taxes!", $advisories[1]->message);
        $this->assertSame('You must discard a card!', $advisories[2]->message);
    }

    #[Test]
    public function playerTriggeringOnlyHandLimitGetsSingleAdvisory(): void
    {
        $player = new Player(new GameSession(), 'Bob');
        $player->cities = 2;
        $player->census = 10;
        $player->cards = 9;

        $advisor = new PlayerAdvisor([new CitySupportRule(new CitySupportCalculator()), new TaxPaymentRule($this->tax()), new HandLimitRule($this->hand())]);
        $advisories = $advisor->advisoriesFor($player);

        $this->assertCount(1, $advisories);
        $this->assertSame('You must discard a card!', $advisories[0]->message);
    }

    private function tax(): TaxCalculator
    {
        return new TaxCalculator(new StockCalculator(GameConfig::gameData()), GameConfig::advanceEffects());
    }

    private function hand(): HandSizeCalculator
    {
        return new HandSizeCalculator(GameConfig::gameData(), GameConfig::advanceEffects());
    }
}
