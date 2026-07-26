<?php

declare(strict_types=1);

namespace App\Tests\Game\Shop;

use App\Game\AdvanceCatalog;
use App\Game\Dto\Advance;
use App\Game\Shop\AdvancePriceResolver;
use App\Game\Shop\Entitlement;
use App\Game\Shop\PlayerBuyer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Userforged\ShopEngine\BuyerInterface;

final class AdvancePriceResolverTest extends WebTestCase
{
    private AdvanceCatalog $advanceCatalog;
    private AdvancePriceResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->advanceCatalog = self::getContainer()->get(AdvanceCatalog::class);
        $this->resolver = new AdvancePriceResolver();
    }

    /**
     * Engineering (cost 160) is craft+science faceted. Pottery (owned) grants
     * craft 10, anatomy (owned) grants craft 5 and science 20 — summing both
     * facets would net 160 - 15 - 20 = 125; the rule credits only the best
     * facet, science's 20, never their sum.
     */
    #[Test]
    public function resolveCreditsOnlyTheBestFacetOnATwoFacetedProductWithAsymmetricCredits(): void
    {
        $engineering = $this->advance('engineering');
        $buyer = $this->makeBuyer(['pottery', 'anatomy']);

        $net = $this->resolver->resolve($engineering, $buyer);

        $this->assertSame(140, $net);
    }

    /**
     * Roadbuilding's named credit is granted by owning engineering (20), and
     * merges with whatever elective credit the buyer already banked under the
     * same key (15) — the two sources sum, neither overrides the other.
     */
    #[Test]
    public function resolveSumsNamedCreditsFromOwnedAdvancesAndElectiveCreditsTogether(): void
    {
        $roadbuilding = $this->advance('roadbuilding');
        $buyer = $this->makeBuyer(['engineering'], [new Entitlement('roadbuilding', 15, 'elective')]);

        $net = $this->resolver->resolve($roadbuilding, $buyer);

        $this->assertSame(175, $net);
    }

    #[Test]
    public function resolveMergesElectiveCreditsIntoFacetCreditsWithNoAdvancesOwned(): void
    {
        $pottery = $this->advance('pottery');
        $buyer = $this->makeBuyer([], [new Entitlement('craft', 25, 'elective')]);

        $net = $this->resolver->resolve($pottery, $buyer);

        $this->assertSame(35, $net);
    }

    #[Test]
    public function resolveNeverReturnsANegativeNetCost(): void
    {
        $pottery = $this->advance('pottery');
        $buyer = $this->makeBuyer(['engineering'], [new Entitlement('craft', 100, 'elective')]);

        $net = $this->resolver->resolve($pottery, $buyer);

        $this->assertSame(0, $net);
    }

    /**
     * PlayerBuyer is the only BuyerInterface implementation the host ever
     * constructs (ShopConnector::buyerFor()/PlayerBuyerProvider), and the
     * resolver leans on that to reach entitlements, which BuyerInterface
     * itself no longer declares. Anything else must be rejected loudly
     * rather than silently treated as crediting nothing.
     */
    #[Test]
    public function resolveRejectsABuyerThatIsNotAPlayerBuyer(): void
    {
        $pottery = $this->advance('pottery');
        $foreignBuyer = new class(Uuid::v4()) implements BuyerInterface {
            public function __construct(
                public Uuid $id,
                public array $ownedKeys = [],
                public array $entitlements = [],
            ) {}
        };

        $this->expectException(\InvalidArgumentException::class);

        $this->resolver->resolve($pottery, $foreignBuyer);
    }

    private function advance(string $name): Advance
    {
        return $this->advanceCatalog->getAdvanceByName($name) ?? throw new \RuntimeException(sprintf('Advance "%s" not found in the real catalog.', $name));
    }

    /**
     * @param list<string>      $ownedKeys
     * @param list<Entitlement> $electiveEntitlements
     */
    private function makeBuyer(array $ownedKeys, array $electiveEntitlements = []): PlayerBuyer
    {
        $ownedEntitlements = [];

        foreach (array_values($this->advanceCatalog->getAdvancesByNames($ownedKeys)) as $advance) {
            foreach ($advance->credits as $scope => $value) {
                $ownedEntitlements[] = new Entitlement($scope, $value, 'advance:'.$advance->key);
            }
        }

        return new PlayerBuyer(id: Uuid::v4(), ownedKeys: $ownedKeys, entitlements: [...$ownedEntitlements, ...$electiveEntitlements]);
    }
}
