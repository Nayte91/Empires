<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Presentation\Operator\UnlockCookie;
use App\State\Game;
use App\Tests\Support\Fixture\GameBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OperatorGateTest extends WebTestCase
{
    private const string PIN = '4821';

    private const string BROWSER_HOST = 'localhost';

    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (initialized in setUp)
    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    #[DataProvider('provideOperatorScreensCases')]
    public function anOpenGameServesEveryOperatorScreenToAnyone(string $pathSuffix): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $this->visitOperator($game, $pathSuffix);

        $this->assertResponseIsSuccessful();
    }

    #[Test]
    #[DataProvider('provideOperatorScreensCases')]
    public function aLockedGameSendsAPhoneWithoutTheCookieToTheUnlockPage(string $pathSuffix): void
    {
        $game = $this->lockedGame();

        $this->visitOperator($game, $pathSuffix);

        $this->assertResponseRedirects($this->unlockPathOf($game, '/game/'.$game->slug.'/operator'.$pathSuffix));
    }

    /** @return iterable<string, array{string}> */
    public static function provideOperatorScreensCases(): iterable
    {
        yield 'the board' => ['/board'];
        yield 'the orders' => ['/orders'];
        yield 'the calamities' => ['/calamities'];
        yield 'the trade' => ['/trade'];
        yield 'the abilities' => ['/abilities'];
        yield 'the point of sale' => ['/pos'];
    }

    #[Test]
    public function aFinishedLockedGameStaysLocked(): void
    {
        $game = GameBuilder::create()->finished()->withOperatorPin(self::PIN)->persist($this->entityManager);

        $this->visitOperator($game, '/board');

        $this->assertResponseStatusCodeSame(Response::HTTP_FOUND);
    }

    #[Test]
    #[DataProvider('provideTheUnlockPageOnlyKeepsANextInsideTheOperatorScreensCases')]
    public function theUnlockPageOnlyKeepsANextInsideTheOperatorScreens(?string $nextSuffix, string $expectedSuffix): void
    {
        $game = $this->lockedGame();
        $query = null === $nextSuffix ? '' : '?next='.rawurlencode($this->nextTargetOf($game, $nextSuffix));

        $crawler = $this->client->request(Request::METHOD_GET, '/game/'.$game->slug.'/operator/unlock'.$query);

        $this->assertResponseIsSuccessful();
        $this->assertSame($this->nextTargetOf($game, $expectedSuffix), $this->unlockTargetOf($crawler));
    }

    /** @return iterable<string, array{?string, string}> */
    public static function provideTheUnlockPageOnlyKeepsANextInsideTheOperatorScreensCases(): iterable
    {
        yield 'an operator screen is kept' => ['/operator/orders', '/operator/orders'];
        yield 'a player screen falls back to the board' => ['/player/alice', '/operator/board'];
        yield 'the unlock page itself falls back to the board' => ['/operator/unlock', '/operator/board'];
        yield 'no next at all falls back to the board' => [null, '/operator/board'];
    }

    #[Test]
    public function theCookieGrantedForAGameOpensItsScreens(): void
    {
        $game = $this->lockedGame();
        $this->carryCookieOf($game);

        $this->visitOperator($game, '/board');

        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function lockingRevokesTheCookieSoTheNextVisitIsSentToTheUnlockPage(): void
    {
        $game = $this->lockedGame();
        $this->carryCookieOf($game);

        $this->client->request(Request::METHOD_POST, '/game/'.$game->slug.'/operator/lock');
        $this->visitOperator($game, '/board');

        $this->assertResponseStatusCodeSame(Response::HTTP_FOUND);
    }

    #[Test]
    public function aCookieGrantedForOneGameLeavesAnotherLocked(): void
    {
        $alpha = $this->lockedGame();
        $beta = $this->lockedGame();
        $this->carryCookieOf($alpha, path: '/');

        $this->visitOperator($beta, '/board');

        $this->assertResponseStatusCodeSame(Response::HTTP_FOUND);
    }

    private function visitOperator(Game $game, string $pathSuffix): Crawler
    {
        return $this->client->request(Request::METHOD_GET, '/game/'.$game->slug.'/operator'.$pathSuffix);
    }

    private function lockedGame(): Game
    {
        return GameBuilder::create()->withOperatorPin(self::PIN)->persist($this->entityManager);
    }

    private function unlockPathOf(Game $game, string $next): string
    {
        return '/game/'.$game->slug.'/operator/unlock?next='.$next;
    }

    private function nextTargetOf(Game $game, string $suffix): string
    {
        return '/game/'.$game->slug.$suffix;
    }

    private function unlockTargetOf(Crawler $crawler): string
    {
        $props = json_decode((string) $crawler->filter('[data-live-props-value]')->attr('data-live-props-value'), true, flags: JSON_THROW_ON_ERROR);

        return $props['next'];
    }

    private function carryCookieOf(Game $game, ?string $path = null): void
    {
        $cookie = self::getContainer()->get(UnlockCookie::class)->grant($game);

        $this->client->getCookieJar()->set(new Cookie($cookie->getName(), (string) $cookie->getValue(), null, $path ?? $cookie->getPath(), self::BROWSER_HOST));
    }
}
