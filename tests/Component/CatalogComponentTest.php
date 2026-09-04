<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Presentation\Shop\CatalogView;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class CatalogComponentTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    protected function setUp(): void
    {
        $this->initEntityManager();

        // renderTwigComponent() renders in-process with no request, and CartStorageInterface is
        // session-backed — this pushes one for the whole file.
        $request = new Request();
        $request->setSession(self::getContainer()->get('session.factory')->createSession());
        self::getContainer()->get(RequestStack::class)->push($request);
    }

    #[Test]
    public function rendersAllFiftyOneAvailableAdvancesAsTiles(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $crawler = $this->renderTwigComponent('organisms:Catalog', [
            'player' => $player,
            'storageKey' => (string) $player->id,
            'view' => CatalogView::pos(),
        ])->crawler();

        $this->assertCount(51, $crawler->filter('button[id^="product-"]'));
    }
}
