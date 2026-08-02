<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Engine\Shop\AdvanceFulfillment;
use App\State\Player;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;
use Symfony\UX\TwigComponent\Test\RenderedComponent;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;

/**
 * App\Presentation\Component\Discounts is a plain TwigComponent aggregating a
 * player's earned credits (App\Rules\Shop\AdvanceCreditsCalculator). These
 * tests exercise the live/spent/empty tri-state the panel exposes through
 * its data-discount-state hook — see issue #24.
 */
final class DiscountsTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    /** All twelve advances carrying the religion category (config/game/advances.yaml). */
    private const array RELIGION_ADVANCES = [
        'deism', 'diaspora', 'enlightenment', 'fundamentalism', 'monument',
        'monotheism', 'mysticism', 'mythology', 'philosophy', 'theocracy',
        'theology', 'universal_doctrine',
    ];

    #[Test]
    public function aNamedCreditWhoseAdvanceIsOwnedIsMarkedSpent(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $fulfillment = self::getContainer()->get(AdvanceFulfillment::class);
        $fulfillment->grant($player->id, ['agriculture']);
        $fulfillment->grant($player->id, ['democracy']);
        $this->entityManager->flush();

        $row = $this->findRowByLabel($this->renderDiscounts($player)->crawler(), 'Democracy');

        $this->assertSame('spent', $row->attr('data-discount-state'));
    }

    #[Test]
    public function aNamedCreditWhoseAdvanceIsNotOwnedIsNotMarkedSpent(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        self::getContainer()->get(AdvanceFulfillment::class)->grant($player->id, ['agriculture']);
        $this->entityManager->flush();

        $row = $this->findRowByLabel($this->renderDiscounts($player)->crawler(), 'Democracy');

        $this->assertSame('live', $row->attr('data-discount-state'));
    }

    #[Test]
    public function aNamedCreditWhoseAdvanceIsOnlyInTheCartIsNotMarkedSpent(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        self::getContainer()->get(AdvanceFulfillment::class)->grant($player->id, ['agriculture']);
        $this->entityManager->flush();

        $request = new Request();
        $request->setSession(self::getContainer()->get('session.factory')->createSession());
        self::getContainer()->get(RequestStack::class)->push($request);
        self::getContainer()->get(CartStorageInterface::class)->save((string) $player->id, Cart::fromKeys(['democracy']));

        $row = $this->findRowByLabel($this->renderDiscounts($player)->crawler(), 'Democracy');

        $this->assertSame('live', $row->attr('data-discount-state'));
    }

    #[Test]
    public function aFacetCreditIsMarkedSpentOnlyOnceEveryAdvanceOfTheCategoryIsOwned(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        self::getContainer()->get(AdvanceFulfillment::class)->grant($player->id, self::RELIGION_ADVANCES);
        $this->entityManager->flush();

        $crawler = $this->renderDiscounts($player)->crawler();

        $this->assertSame('spent', $crawler->filter('tr[data-advance-category="religion"]')->attr('data-discount-state'));
    }

    #[Test]
    public function aFacetCreditWithOneAdvanceOfTheCategoryStillUnownedIsNotMarkedSpent(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $incomplete = \array_slice(self::RELIGION_ADVANCES, 0, 11);
        self::getContainer()->get(AdvanceFulfillment::class)->grant($player->id, $incomplete);
        $this->entityManager->flush();

        $crawler = $this->renderDiscounts($player)->crawler();

        $this->assertSame('live', $crawler->filter('tr[data-advance-category="religion"]')->attr('data-discount-state'));
    }

    #[Test]
    public function aFacetCreditWorthZeroIsDimmedButNotStruck(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $crawler = $this->renderDiscounts($player)->crawler();

        $this->assertSame('empty', $crawler->filter('tr[data-advance-category="religion"]')->attr('data-discount-state'));
    }

    private function renderDiscounts(Player $player): RenderedComponent
    {
        return $this->renderTwigComponent('molecules:Discounts', ['player' => $player]);
    }

    /** Named rows carry no attribute of their own, so their credit is found by row label. */
    private function findRowByLabel(Crawler $crawler, string $label): Crawler
    {
        return $crawler->filterXPath(\sprintf("//tbody/tr[td[1][normalize-space()='%s']]", $label));
    }
}
