<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class PlayerBoardTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function anOwnedAdvanceIsRenderedInTheOwnedAdvancesSection(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->ownAdvances(['pottery']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->crawler();

        $this->assertCount(1, $rendered->filter('section[aria-label="Owned advances"] img#product-pottery'));
    }

    #[Test]
    public function theBoardListensOnItsOwnPlayersTopicAndNoOther(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->crawler();

        $this->assertSame(
            'empires/game/'.$player->game->id.'/player/'.$player->id,
            $rendered->filter('[data-mercure-refresh-topic-value]')->attr('data-mercure-refresh-topic-value'),
        );
    }
}
