<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\Game;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class OperatorUnlockTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    private const string PIN = '4821';

    #[Test]
    public function aWrongPinReportsAnErrorEmptiesTheFieldAndStaysOnThePage(): void
    {
        $game = $this->lockedGame();

        $component = $this->unlockOf($game)->set('pin', '0000');
        $component->call('unlock');

        $this->assertSame(Response::HTTP_OK, $component->response()->getStatusCode(), (string) $component->response()->getContent());
        $this->assertCount(1, new Crawler((string) $component->response()->getContent())->filter('[data-error="pin"]'));
        $this->assertSame('', $component->component()->pin);
    }

    #[Test]
    public function theRightPinRedirectsToNextWithTheCookieScopedToTheGamesOperatorPath(): void
    {
        $game = $this->lockedGame();

        $component = $this->unlockOf($game)->set('pin', self::PIN);
        $component->call('unlock');

        $response = $component->response();
        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame($this->nextOf($game), $response->headers->get('Location'));
        $this->assertSame('/game/'.$game->slug.'/operator', $this->operatorCookieOf($response)->getPath());
    }

    private function lockedGame(): Game
    {
        return GameBuilder::create()->withOperatorPin(self::PIN)->persist($this->entityManager);
    }

    private function unlockOf(Game $game): TestLiveComponent
    {
        return $this->createLiveComponent('OperatorUnlock', ['game' => $game, 'next' => $this->nextOf($game)]);
    }

    private function nextOf(Game $game): string
    {
        return '/game/'.$game->slug.'/operator/orders';
    }

    private function operatorCookieOf(Response $response): Cookie
    {
        return array_find($response->headers->getCookies(), static fn (Cookie $cookie): bool => 'empires_operator' === $cookie->getName())
            ?? throw new \RuntimeException('No empires_operator cookie on the response.');
    }
}
