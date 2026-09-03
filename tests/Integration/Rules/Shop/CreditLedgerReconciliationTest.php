<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules\Shop;

use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Ruleset\ScenarioRegistry;
use App\Engine\Shop\AdvanceFulfillment;
use App\Rules\Shop\Entitlement;
use App\Rules\Shop\ShopConnector;
use App\State\CreditEntry;
use App\State\CreditSource;
use App\State\Region;
use App\Infrastructure\Repository\PlayerRepository;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CreditLedgerReconciliationTest extends WebTestCase
{
    use GameFixtureTrait;

    private AdvanceRegistry $advanceRegistry;
    private ScenarioRegistry $scenarioRegistry;
    private AdvanceFulfillment $fulfillment;

    protected function setUp(): void
    {
        $this->initEntityManager();

        $playerRepository = self::getContainer()->get(PlayerRepository::class);
        $this->advanceRegistry = self::getContainer()->get(AdvanceRegistry::class);
        $this->scenarioRegistry = self::getContainer()->get(ScenarioRegistry::class);
        $this->fulfillment = new AdvanceFulfillment($playerRepository, $this->advanceRegistry);
    }

    #[Test]
    public function theLedgersBalancePerScopeMatchesTheScenariosStartingCreditsPlusTheCurrentlyOwnedAdvancesCredits(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withPlayerCount(3)->build())->persist($this->entityManager);

        foreach ($this->scenarioRegistry->find(3, $player->game->region)->startingCredits ?? [] as $scope => $value) {
            $player->postCredit(new CreditEntry(0, $scope, $value, CreditSource::Scenario, 'scenario:3'));
        }

        $this->fulfillment->grant($player->id, ['pottery', 'agriculture'], $player->game->currentTurn);
        $this->fulfillment->revoke($player->id, ['pottery']);
        $this->entityManager->flush();

        $this->assertSame($this->expectedBalances(3, $player->advances), $this->actualBalances($player->creditLedger));
    }

    #[Test]
    public function theEntitlementsReadTheLedgerInWritingOrderAndNeverItsTurns(): void
    {
        $datedInOrder = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $datedBackwards = PlayerBuilder::named('Bob')->persist($this->entityManager);
        $shopConnector = self::getContainer()->get(ShopConnector::class);

        foreach ([[9, 'art', 10], [3, 'art', -6], [1, 'craft', 5]] as [$backwardsTurn, $scope, $value]) {
            $datedInOrder->postCredit(new CreditEntry(3, $scope, $value, CreditSource::Shop, 'advance:pottery'));
            $datedBackwards->postCredit(new CreditEntry($backwardsTurn, $scope, $value, CreditSource::Shop, 'advance:pottery'));
        }

        $this->assertEquals([new Entitlement('art', 4), new Entitlement('craft', 5)], $shopConnector->buyerFor($datedInOrder)->entitlements);
        $this->assertEquals($shopConnector->buyerFor($datedInOrder)->entitlements, $shopConnector->buyerFor($datedBackwards)->entitlements);
    }

    /**
     * @param list<string> $ownedAdvanceKeys
     *
     * @return array<string, int>
     */
    private function expectedBalances(int $playerCount, array $ownedAdvanceKeys): array
    {
        $expected = $this->scenarioRegistry->find($playerCount, Region::West)->startingCredits ?? [];

        foreach ($this->advanceRegistry->getAdvancesByNames($ownedAdvanceKeys) as $advance) {
            foreach ($advance->credits as $scope => $value) {
                $expected[$scope] = ($expected[$scope] ?? 0) + $value;
            }
        }

        ksort($expected);

        return $expected;
    }

    /**
     * @param list<CreditEntry> $creditLedger
     *
     * @return array<string, int>
     */
    private function actualBalances(array $creditLedger): array
    {
        $balances = [];

        foreach ($creditLedger as $entry) {
            $balances[$entry->scope] = ($balances[$entry->scope] ?? 0) + $entry->value;
        }

        $balances = array_filter($balances, static fn (int $value): bool => 0 !== $value);
        ksort($balances);

        return $balances;
    }
}
