<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules\Shop;

use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Ruleset\Advance;
use App\Rules\Shop\AdvancePriceResolver;
use App\Rules\Shop\Entitlement;
use App\Rules\Shop\PlayerBuyer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Userforged\ShopEngine\BuyerInterface;

final class AdvancePriceResolverTest extends WebTestCase
{
    private AdvanceRegistry $advanceRegistry;
    private AdvancePriceResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->advanceRegistry = self::getContainer()->get(AdvanceRegistry::class);
        $this->resolver = new AdvancePriceResolver();
    }

    #[Test]
    public function resolveCreditsOnlyTheBestFacetOnATwoFacetedProductWithAsymmetricCredits(): void
    {
        $engineering = $this->advance('engineering');
        $buyer = $this->makeBuyer(['pottery', 'anatomy']);

        $net = $this->resolver->resolve($engineering, $buyer);

        $this->assertSame(140, $net);
    }

    #[Test]
    public function resolveSumsNamedCreditsFromOwnedAdvancesAndElectiveCreditsTogether(): void
    {
        $roadbuilding = $this->advance('roadbuilding');
        $buyer = $this->makeBuyer(['engineering'], [new Entitlement('roadbuilding', 15)]);

        $net = $this->resolver->resolve($roadbuilding, $buyer);

        $this->assertSame(175, $net);
    }

    #[Test]
    public function resolveMergesElectiveCreditsIntoFacetCreditsWithNoAdvancesOwned(): void
    {
        $pottery = $this->advance('pottery');
        $buyer = $this->makeBuyer([], [new Entitlement('craft', 25)]);

        $net = $this->resolver->resolve($pottery, $buyer);

        $this->assertSame(35, $net);
    }

    #[Test]
    public function resolveNeverReturnsANegativeNetCost(): void
    {
        $pottery = $this->advance('pottery');
        $buyer = $this->makeBuyer(['engineering'], [new Entitlement('craft', 100)]);

        $net = $this->resolver->resolve($pottery, $buyer);

        $this->assertSame(0, $net);
    }

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
        return $this->advanceRegistry->getAdvanceByName($name) ?? throw new \RuntimeException(sprintf('Advance "%s" not found in the real catalog.', $name));
    }

    /**
     * @param list<string>      $ownedKeys
     * @param list<Entitlement> $electiveEntitlements
     */
    private function makeBuyer(array $ownedKeys, array $electiveEntitlements = []): PlayerBuyer
    {
        $ownedEntitlements = [];

        foreach (array_values($this->advanceRegistry->getAdvancesByNames($ownedKeys)) as $advance) {
            foreach ($advance->credits as $scope => $value) {
                $ownedEntitlements[] = new Entitlement($scope, $value);
            }
        }

        return new PlayerBuyer(id: Uuid::v4(), ownedKeys: $ownedKeys, entitlements: [...$ownedEntitlements, ...$electiveEntitlements]);
    }
}
