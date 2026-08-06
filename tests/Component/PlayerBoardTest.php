<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Engine\Shop\AdvanceFulfillment;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class PlayerBoardTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function getPlayerBoardReturnsTwoHundredWithLiveAndMercureWiring(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $client = self::getClient(self::getContainer()->get('test.client'));
        $client->request('GET', '/'.$player->game->slug.'/player/'.$player->slug);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-controller~="live"]');

        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('empires/game/'.$player->game->id, $html);
    }

    #[Test]
    public function unknownPlayerSlugReturns404(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $client = self::getClient(self::getContainer()->get('test.client'));
        $client->request('GET', '/'.$player->game->slug.'/player/does-not-exist');

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * Scoped to the pickers' own dialog ids rather than to every show-modal trigger on the board:
     * the board hosts unrelated dialogs (rename), and a census of all of them turns this red for
     * reasons that have nothing to do with stat pickers. The exact labels and values, in order,
     * are still what is asserted, so a missing or mis-wired picker cannot slip through.
     */
    #[Test]
    public function allFiveStatPickerTriggerButtonsAreRendered(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render();

        $labels = array_map(
            static fn (string $text): string => trim(preg_replace('/\s+/', ' ', $text) ?? ''),
            $rendered->crawler()->filter('button[command="show-modal"][commandfor^="stat-picker-"]')->each(
                static fn ($node) => $node->text(),
            ),
        );

        $this->assertSame(['Cities 0', 'Ships 0', 'Population 1', 'Treasury 0', 'Cards 0'], $labels);
    }

    /**
     * The heading quotes the advances term of the score, so it can never drift from what the score
     * itself counts. Zero is printed rather than hidden: a heading that keeps its shape is easier
     * to read turn over turn, and a zero in a named source is itself information.
     */
    #[Test]
    public function theAdvancesHeadingCarriesWhatThoseAdvancesAreWorth(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->ownAdvances(['pottery', 'agriculture']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->crawler();

        $this->assertSame('Advances (4 Victory Points)', trim($rendered->filter('section[aria-label="Owned advances"] h2')->text()));
    }

    #[Test]
    public function theAdvancesHeadingSaysOneVictoryPointInTheSingular(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->ownAdvances(['pottery']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->crawler();

        $this->assertSame('Advances (1 Victory Point)', trim($rendered->filter('section[aria-label="Owned advances"] h2')->text()));
    }

    #[Test]
    public function theAdvancesHeadingStillCountsZeroForAPlayerOwningNothing(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->crawler();

        $this->assertSame('Advances (0 Victory Points)', trim($rendered->filter('section[aria-label="Owned advances"] h2')->text()));
    }

    #[Test]
    public function ownedAdvancesAreRenderedWithoutAPurchaseButton(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->ownAdvances(['pottery']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->toString();

        $this->assertStringContainsString('id="product-pottery"', $rendered);
        $this->assertStringContainsString('alt="pottery card"', $rendered);
        $this->assertStringNotContainsString('<button', $this->extractOwnedAdvancesSection($rendered));
    }

    #[Test]
    public function discountsAreRenderedForAPlayerOwningAgriculture(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        self::getContainer()->get(AdvanceFulfillment::class)->grant($player->id, ['agriculture']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->toString();

        $this->assertStringContainsString('Craft', $rendered);
        $this->assertMatchesRegularExpression('/Craft<\/td>\s*<td><b>10</', $rendered);
        $this->assertMatchesRegularExpression('/Science<\/td>\s*<td><b>5</', $rendered);
        $this->assertMatchesRegularExpression('/Democracy<\/td>\s*<td><b>20</', $rendered);
    }

    #[Test]
    public function discountCategoryRowsCarryTheOfficialCategoryColor(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->ownAdvances(['agriculture']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->toString();

        // Agriculture provides credits for craft and science categories
        $this->assertStringContainsString('data-advance-category="craft"', $rendered);
        $this->assertStringContainsString('data-advance-category="science"', $rendered);
    }

    #[Test]
    public function mercureRefreshFiltersToPlayerUpdated(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->toString();

        $this->assertStringContainsString('data-mercure-refresh-events-value', $rendered);
        $this->assertStringContainsString('player-updated', $rendered);
        $this->assertStringNotContainsString('order-updated', $rendered);
    }

    #[Test]
    public function bodyBackgroundIsColoredByThePlayersEmpire(): void
    {
        $withEmpire = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $withEmpire->empire = 'minoa';
        $this->entityManager->flush();

        $client = self::getClient(self::getContainer()->get('test.client'));

        $client->request('GET', '/'.$withEmpire->game->slug.'/player/'.$withEmpire->slug);
        $boardContent = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('data-empire="minoa"', $boardContent);
        $this->assertStringContainsString('--empire-minoa: #a4ce53', $boardContent);
        $this->assertMatchesRegularExpression('/<body[^>]*data-empire="minoa"/', $boardContent);

        $client->request('GET', '/'.$withEmpire->game->slug.'/player/'.$withEmpire->slug.'/shop');
        $this->assertStringContainsString('data-empire="minoa"', (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function theBoardOffersARenameTriggerForTheDialogHoldingThePlayersName(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $crawler = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->crawler();

        $dialogId = 'rename-player-'.$player->id;
        $this->assertCount(1, $crawler->filter('button[command="show-modal"][commandfor="'.$dialogId.'"]'));
        $this->assertCount(1, $crawler->filter('dialog[id="'.$dialogId.'"]'));
    }

    #[Test]
    public function theRenameInputIsPrefilledWithTheCurrentName(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $crawler = $this->createLiveComponent('PlayerBoard', ['player' => $player])->render()->crawler();

        $input = $crawler->filter('dialog[id="rename-player-'.$player->id.'"] input[type="text"]');

        $this->assertCount(1, $input);
        $this->assertSame('Alice', $input->attr('value'));
    }

    /**
     * The stored name is normalised the way GameCreator::addPlayer() normalises a created one, and
     * the slug follows it — which is the whole point of the redirect: the page the player is
     * standing on is served under that slug, so leaving them on the old URL leaves them on a 404.
     */
    #[Test]
    public function renamingAPlayerWritesTheNewNameAndSlugAndRedirectsToTheirNewBoardUrl(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $gameSlug = $player->game->slug;
        $playerId = $player->id;

        $component = $this->createLiveComponent('PlayerBoard', ['player' => $player])
            ->set('newName', 'alice the great')
        ;
        $component->call('rename');

        $response = $component->response();
        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('/'.$gameSlug.'/player/alice-the-great', $response->headers->get('Location'));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Player::class)->find($playerId);

        $this->assertInstanceOf(Player::class, $reloaded);
        $this->assertSame('Alice the great', $reloaded->name);
        $this->assertSame('alice-the-great', $reloaded->slug);
    }

    /**
     * Uniqueness is scoped to the game, as uniq_player_game_slug is — a name another game already
     * uses is none of this board's business.
     */
    #[Test]
    public function renamingToANameHeldByAPlayerOfAnotherGameIsAllowed(): void
    {
        $otherGame = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($otherGame)->persist($this->entityManager);

        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $playerId = $player->id;

        $component = $this->createLiveComponent('PlayerBoard', ['player' => $player])
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

    /**
     * Both blankness and uniqueness are judged on the slug, deliberately: two visually distinct
     * names that slugify identically are a real collision, since the slug is the URL.
     */
    #[Test]
    #[DataProvider('provideARefusedRenameKeepsTheOldNameCases')]
    public function aRefusedRenameKeepsTheOldName(string $newName, string $expectedMessage): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $playerId = $alice->id;

        $rendered = $this->createLiveComponent('PlayerBoard', ['player' => $alice])
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

        yield 'a name one character over the shared name limit' => [str_repeat('a', Player::MAX_NAME_LENGTH + 1), 'Name cannot be longer than 30 characters.'];
    }

    /**
     * The rename door and the creation door share Player::MAX_NAME_LENGTH, so a name GameCreator
     * accepts must land here too. Left at the column width, this door would let a player rename
     * themselves to something their own game could never have created them with.
     */
    #[Test]
    public function renamingToANameOfExactlyTheMaximumLengthIsAccepted(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $playerId = $player->id;

        $component = $this->createLiveComponent('PlayerBoard', ['player' => $player])
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

    /**
     * <form method="dialog"> closes the dialog on every submit, refusal included, so a message
     * rendered inside it would be a silent failure: the player would see the dialog vanish and
     * their name unchanged, with nothing saying why.
     */
    #[Test]
    public function theMessageOfARefusedRenameIsRenderedOutsideTheDialog(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $crawler = $this->createLiveComponent('PlayerBoard', ['player' => $alice])
            ->set('newName', 'Bob')
            ->call('rename')
            ->render()
            ->crawler()
        ;

        $this->assertCount(1, $crawler->filter('[data-error="newName"][role="alert"]'));
        $this->assertCount(1, $crawler->filter('dialog input[type="text"]'));
        $this->assertCount(0, $crawler->filter('dialog [data-error="newName"]'));
    }

    /**
     * Scopes assertions to the owned-advances section, as opposed to the
     * whole board (which renders other, unrelated <button> elements).
     */
    private function extractOwnedAdvancesSection(string $html): string
    {
        $start = strpos($html, '<section aria-label="Owned advances">');
        $this->assertNotFalse($start, 'Owned advances section not found in rendered output.');

        $end = strpos($html, '</section>', $start);
        $this->assertNotFalse($end, 'Owned advances section not closed in rendered output.');

        return substr($html, $start, $end - $start);
    }
}
