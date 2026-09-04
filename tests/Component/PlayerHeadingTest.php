<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\Fixture\Tables;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class PlayerHeadingTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function theHeadingOffersARenameTriggerForTheDialogHoldingThePlayersName(): void
    {
        $player = Tables::seat(Tables::westTable($this->entityManager), 'Alice');

        $crawler = $this->render($player);

        $dialogId = 'rename-player-'.$player->id;
        $this->assertCount(1, $crawler->filter('#page-title button[command="show-modal"][commandfor="'.$dialogId.'"]'));
        $this->assertCount(1, $crawler->filter('dialog[id="'.$dialogId.'"]'));
    }

    #[Test]
    public function theRenameFieldOpensOnTheCurrentName(): void
    {
        $player = Tables::seat(Tables::westTable($this->entityManager), 'Alice');

        $component = $this->createLiveComponent('molecules:PlayerHeading', ['player' => $player])->component();

        $this->assertSame('Alice', $component->newName);
    }

    #[Test]
    public function renamingAPlayerWritesTheNewNameAndSlugAndRedirectsToTheirNewBoardUrl(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $gameSlug = $player->game->slug;
        $playerId = $player->id;

        $component = $this->createLiveComponent('molecules:PlayerHeading', ['player' => $player])
            ->set('newName', 'alice the great')
        ;
        $component->call('rename');

        $response = $component->response();
        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('/game/'.$gameSlug.'/player/alice-the-great', $response->headers->get('Location'));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Player::class)->find($playerId);

        $this->assertInstanceOf(Player::class, $reloaded);
        $this->assertSame('Alice the great', $reloaded->name);
        $this->assertSame('alice-the-great', $reloaded->slug);
    }

    #[Test]
    public function renamingToANameHeldByAPlayerOfAnotherGameIsAllowed(): void
    {
        $otherGame = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($otherGame)->persist($this->entityManager);

        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $playerId = $player->id;

        $component = $this->createLiveComponent('molecules:PlayerHeading', ['player' => $player])
            ->set('newName', 'Bob')
        ;
        $component->call('rename');

        $response = $component->response();
        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode(), (string) $response->getContent());

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Player::class)->find($playerId);

        $this->assertInstanceOf(Player::class, $reloaded);
        $this->assertSame('Bob', $reloaded->name);
        $this->assertSame('bob', $reloaded->slug);
    }

    #[Test]
    #[DataProvider('provideARefusedRenameKeepsTheOldNameCases')]
    public function aRefusedRenameKeepsTheOldName(string $newName, string $expectedMessage): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $playerId = $alice->id;

        $rendered = $this->createLiveComponent('molecules:PlayerHeading', ['player' => $alice])
            ->set('newName', $newName)
            ->call('rename')
            ->render()
        ;

        $this->assertSame($expectedMessage, trim($rendered->crawler()->filter('[data-error="newName"]')->text()));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Player::class)->find($playerId);

        $this->assertInstanceOf(Player::class, $reloaded);
        $this->assertSame('Alice', $reloaded->name);
        $this->assertSame('alice', $reloaded->slug);
    }

    public static function provideARefusedRenameKeepsTheOldNameCases(): iterable
    {
        yield 'an empty name' => ['', 'Player name is required.'];

        yield 'whitespace only' => ['   ', 'Player name is required.'];

        yield 'punctuation that slugifies to nothing' => ['---', 'Player name is required.'];

        yield 'a name another player of the game already holds' => ['Bob', 'Name already taken.'];

        yield 'a name that slugifies onto another players' => ['BOB', 'Name already taken.'];

        yield 'a name one character over the shared name limit' => [str_repeat('a', Player::MAX_NAME_LENGTH + 1), 'Name cannot be longer than 20 characters.'];
    }

    #[Test]
    public function renamingToANameOfExactlyTheMaximumLengthIsAccepted(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $playerId = $player->id;

        $component = $this->createLiveComponent('molecules:PlayerHeading', ['player' => $player])
            ->set('newName', str_repeat('a', Player::MAX_NAME_LENGTH))
        ;
        $component->call('rename');

        $response = $component->response();
        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode(), (string) $response->getContent());

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Player::class)->find($playerId);

        $this->assertInstanceOf(Player::class, $reloaded);
        $this->assertSame('A'.str_repeat('a', Player::MAX_NAME_LENGTH - 1), $reloaded->name);
    }

    #[Test]
    public function theHeadingRefreshesOnTheTopicATurnChangeReachesThePlayerBy(): void
    {
        $player = Tables::seat(Tables::westTable($this->entityManager), 'Alice');

        $crawler = $this->render($player);

        $this->assertSame(
            'empires/game/'.$player->game->id.'/player/'.$player->id.'/shop',
            $crawler->filter('div[data-controller~="mercure-refresh"]')->attr('data-mercure-refresh-topic-value'),
        );
    }

    private function render(Player $player): Crawler
    {
        return $this->createLiveComponent('molecules:PlayerHeading', ['player' => $player])->render()->crawler();
    }
}
