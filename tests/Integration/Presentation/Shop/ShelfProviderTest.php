<?php

declare(strict_types=1);

namespace App\Tests\Integration\Presentation\Shop;

use App\Engine\Shop\AdvanceFulfillment;
use App\Presentation\Shop\CatalogSort;
use App\Presentation\Shop\CatalogView;
use App\Presentation\Shop\ShelfProvider;
use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\State\Player;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;

final class ShelfProviderTest extends WebTestCase
{
    use GameFixtureTrait;

    private ShelfProvider $shelfProvider;

    /** CartStorageInterface is session-backed: with no request on the stack, the first load() throws. */
    protected function setUp(): void
    {
        $this->initEntityManager();

        $this->shelfProvider = self::getContainer()->get(ShelfProvider::class);

        $request = new Request();
        $request->setSession(self::getContainer()->get('session.factory')->createSession());
        self::getContainer()->get(RequestStack::class)->push($request);
    }

    #[Test]
    public function theKioskShelfIsOrderedByWhatThePlayerActuallyPays(): void
    {
        $player = $this->discountedPlayer();

        $kiosk = $this->shelfProvider->rowsFor($player, CatalogView::kiosk(false, null), (string) $player->id);
        $pos = $this->shelfProvider->rowsFor($player, CatalogView::pos(), (string) $player->id);

        $netCosts = array_map(static fn (array $row): int => $row['product']->netCost, $kiosk);
        $ascending = $netCosts;
        sort($ascending);

        $this->assertSame($ascending, $netCosts);
        $this->assertNotSame($this->keysOf($pos), $this->keysOf($kiosk));
    }

    #[Test]
    public function sortingByNamePutsTheShelfInAlphabeticalOrder(): void
    {
        $player = $this->discountedPlayer();

        $byName = $this->shelfProvider->rowsFor($player, CatalogView::kiosk(false, null, CatalogSort::Name), (string) $player->id);
        $byNetPrice = $this->shelfProvider->rowsFor($player, CatalogView::kiosk(false, null), (string) $player->id);

        $names = array_map(static fn (array $row): string => $row['advance']->name, $byName);
        $alphabetical = $names;
        sort($alphabetical);

        $this->assertSame($alphabetical, $names);
        $this->assertNotSame($this->keysOf($byNetPrice), $this->keysOf($byName));
    }

    /** Compared against the registry, not against ascending list prices: the latter passes with no sort at all. */
    #[Test]
    public function thePosShelfKeepsTheOrderTheRegistryProvides(): void
    {
        $player = $this->discountedPlayer();

        $keys = $this->keysOf($this->shelfProvider->rowsFor($player, CatalogView::pos(), (string) $player->id));

        $registryOrder = array_values(array_filter(
            array_map(
                static fn (Advance $advance): string => $advance->key,
                self::getContainer()->get(AdvanceRegistry::class)->getAdvances(),
            ),
            static fn (string $key): bool => \in_array($key, $keys, true),
        ));

        $this->assertSame($registryOrder, $keys);
    }

    #[Test]
    public function aProductInTheCartLeavesTheShelfWhileTheRestStays(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        self::getContainer()->get(CartStorageInterface::class)->save((string) $player->id, Cart::fromKeys(['pottery']));

        $keys = $this->keysOf($this->shelfProvider->rowsFor($player, CatalogView::kiosk(false, null), (string) $player->id));

        $this->assertNotContains('pottery', $keys);
        $this->assertContains('democracy', $keys);
    }

    #[Test]
    public function aCartUnderAnotherStorageKeyLeavesTheShelfUntouched(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        self::getContainer()->get(CartStorageInterface::class)->save('another-key', Cart::fromKeys(['pottery']));

        $keys = $this->keysOf($this->shelfProvider->rowsFor($player, CatalogView::kiosk(false, null), (string) $player->id));

        $this->assertContains('pottery', $keys);
    }

    #[Test]
    public function anOwnedAdvanceLeavesTheShelf(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->ownAdvances(['pottery']);
        $this->entityManager->flush();

        $keys = $this->keysOf($this->shelfProvider->rowsFor($player, CatalogView::kiosk(false, null), (string) $player->id));

        $this->assertNotContains('pottery', $keys);
        $this->assertCount(50, $keys);
    }

    private function discountedPlayer(): Player
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        self::getContainer()->get(AdvanceFulfillment::class)->grant($player->id, ['agriculture'], $player->game->currentTurn);
        $this->entityManager->flush();

        return $player;
    }

    /**
     * @param list<array{advance: Advance}> $rows
     *
     * @return list<string>
     */
    private function keysOf(array $rows): array
    {
        return array_map(static fn (array $row): string => $row['advance']->key, $rows);
    }
}
