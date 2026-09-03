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

/** The three states are indistinguishable in the markup: read data-discount-state, never the text. */
final class DiscountsTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    private const array EVERY_ADVANCE_CARRYING_THE_RELIGION_CATEGORY = [
        'deism', 'diaspora', 'enlightenment', 'fundamentalism', 'monument',
        'monotheism', 'mysticism', 'mythology', 'philosophy', 'theocracy',
        'theology', 'universal_doctrine',
    ];

    #[Test]
    public function aNamedCreditWhoseAdvanceIsOwnedIsMarkedSpent(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $fulfillment = self::getContainer()->get(AdvanceFulfillment::class);
        $fulfillment->grant($player->id, ['agriculture'], $player->game->currentTurn, $player->game->currentTurn);
        $fulfillment->grant($player->id, ['democracy'], $player->game->currentTurn, $player->game->currentTurn);
        $this->entityManager->flush();

        $row = $this->findRowByLabel($this->renderDiscounts($player)->crawler(), 'Democracy');

        $this->assertSame('spent', $row->attr('data-discount-state'));
    }

    #[Test]
    public function aNamedCreditWhoseAdvanceIsNotOwnedIsNotMarkedSpent(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        self::getContainer()->get(AdvanceFulfillment::class)->grant($player->id, ['agriculture'], $player->game->currentTurn, $player->game->currentTurn);
        $this->entityManager->flush();

        $row = $this->findRowByLabel($this->renderDiscounts($player)->crawler(), 'Democracy');

        $this->assertSame('live', $row->attr('data-discount-state'));
    }

    #[Test]
    public function aNamedCreditWhoseAdvanceIsOnlyInTheCartIsNotMarkedSpent(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        self::getContainer()->get(AdvanceFulfillment::class)->grant($player->id, ['agriculture'], $player->game->currentTurn, $player->game->currentTurn);
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
        self::getContainer()->get(AdvanceFulfillment::class)->grant($player->id, self::EVERY_ADVANCE_CARRYING_THE_RELIGION_CATEGORY, $player->game->currentTurn);
        $this->entityManager->flush();

        $crawler = $this->renderDiscounts($player)->crawler();

        $this->assertSame('spent', $crawler->filter('tr[data-advance-category="religion"]')->attr('data-discount-state'));
    }

    #[Test]
    public function aFacetCreditWithOneAdvanceOfTheCategoryStillUnownedIsNotMarkedSpent(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $incomplete = \array_slice(self::EVERY_ADVANCE_CARRYING_THE_RELIGION_CATEGORY, 0, 11);
        self::getContainer()->get(AdvanceFulfillment::class)->grant($player->id, $incomplete, $player->game->currentTurn);
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
