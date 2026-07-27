<?php

declare(strict_types=1);

namespace App\Tests\Game\Shop;

use App\Game\AdvanceCatalog;
use App\Game\ScenarioCatalog;
use App\Game\Shop\AdvanceFulfillment;
use App\Game\Shop\CreditEntry;
use App\Game\Shop\CreditSource;
use App\Repository\PlayerRepository;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Independent double-check of the credit ledger: it recomputes the balance a
 * player should have — the scenario's starting credits plus the credits
 * blocks of the advances they currently own — without ever reading the
 * ledger itself, then compares that to what the ledger actually holds. A
 * missing grant()/revoke() counterpart (e.g. a future erasure path that
 * forgets to call revoke()) would desynchronize player->advances from the
 * ledger and show up here as a mismatch, even though neither
 * AdvanceFulfillmentTest nor CreateGameHandlerTest would notice on their own.
 */
final class CreditLedgerReconciliationTest extends WebTestCase
{
    use GameFixtureTrait;

    private AdvanceCatalog $advanceCatalog;
    private ScenarioCatalog $scenarioCatalog;
    private AdvanceFulfillment $fulfillment;

    protected function setUp(): void
    {
        $this->initEntityManager();

        $playerRepository = self::getContainer()->get(PlayerRepository::class);
        $this->advanceCatalog = self::getContainer()->get(AdvanceCatalog::class);
        $this->scenarioCatalog = self::getContainer()->get(ScenarioCatalog::class);
        $this->fulfillment = new AdvanceFulfillment($playerRepository, $this->advanceCatalog);
    }

    #[Test]
    public function theLedgersBalancePerScopeMatchesTheScenariosStartingCreditsPlusTheCurrentlyOwnedAdvancesCredits(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withPlayerCount(3)->build())->persist($this->entityManager);

        foreach ($this->scenarioCatalog->startingCreditsFor(3) as $scope => $value) {
            $player->postCredit(new CreditEntry(0, $scope, $value, CreditSource::Scenario, 'scenario:3'));
        }

        $this->fulfillment->grant($player->id, ['pottery', 'agriculture']);
        $this->fulfillment->revoke($player->id, ['pottery']);
        $this->entityManager->flush();

        $this->assertSame($this->expectedBalances(3, $player->advances), $this->actualBalances($player->creditLedger));
    }

    /**
     * @param list<string> $ownedAdvanceKeys
     *
     * @return array<string, int>
     */
    private function expectedBalances(int $playerCount, array $ownedAdvanceKeys): array
    {
        $expected = $this->scenarioCatalog->startingCreditsFor($playerCount);

        foreach ($this->advanceCatalog->getAdvancesByNames($ownedAdvanceKeys) as $advance) {
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
