<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class GameHeadingTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function theHeadingRefreshesOnTheOperatorTopic(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $crawler = $this->createLiveComponent('molecules:GameHeading', ['game' => $game])->render()->crawler();

        $this->assertSame(
            'empires/game/'.$game->id.'/operator',
            $crawler->filter('div[data-controller~="mercure-refresh"]')->attr('data-mercure-refresh-topic-value'),
        );
    }
}
