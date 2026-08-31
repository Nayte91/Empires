<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Rules\Ruleset\RulebookRegistry;
use App\State\Game;
use App\State\Region;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class HelpTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    #[Test]
    #[DataProvider('provideAGameInOneBlockIsHandedThatBlocksRulebookAloneCases')]
    public function aGameInOneBlockIsHandedThatBlocksRulebookAlone(Region $region): void
    {
        $game = GameBuilder::create()->withRegion($region)->persist($this->entityManager);

        $outbound = $this->outboundLinks($game);

        $this->assertSame([$this->registry()->forRegion($region)->url], $outbound);
    }

    /** @return iterable<string, array{Region}> */
    public static function provideAGameInOneBlockIsHandedThatBlocksRulebookAloneCases(): iterable
    {
        yield 'the west' => [Region::West];

        yield 'the east' => [Region::East];
    }

    #[Test]
    public function aCombinedGameLeadsWithTheScenariosBookletThenOneRulebookOrTheOther(): void
    {
        $game = GameBuilder::create()->withRegion(null)->persist($this->entityManager);

        $outbound = $this->outboundLinks($game);

        $this->assertCount(2, $outbound);
        $this->assertSame($this->registry()->scenarios()->url, $outbound[0]);
        $this->assertContains($outbound[1], [
            $this->registry()->forRegion(Region::West)->url,
            $this->registry()->forRegion(Region::East)->url,
        ]);
    }

    #[Test]
    public function onlyThePublishersLinksLeaveTheApp(): void
    {
        $game = GameBuilder::create()->withRegion(Region::West)->persist($this->entityManager);

        $crawler = $this->renderTwigComponent('Help', ['game' => $game])->crawler();

        $this->assertCount(1, $crawler->filter('a:not([target])'));
        $this->assertStringEndsWith('/trade-cards', (string) $crawler->filter('a:not([target])')->attr('href'));
        $this->assertSame(
            ['noopener'],
            array_unique($crawler->filter('a[target]')->each(static fn (Crawler $a): ?string => $a->attr('rel'))),
        );
    }

    /** @return list<string> */
    private function outboundLinks(Game $game): array
    {
        return $this->renderTwigComponent('Help', ['game' => $game])
            ->crawler()
            ->filter('a[target="_blank"]')
            ->each(static fn (Crawler $link): string => (string) $link->attr('href'));
    }

    private function registry(): RulebookRegistry
    {
        return self::getContainer()->get(RulebookRegistry::class);
    }
}
