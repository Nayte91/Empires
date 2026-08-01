<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\ASTVersion;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class BatchEditorTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function theDialogIsAbsentUntilTheBatchIsOpened(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('BatchEditor', ['game' => $game])->render();

        $this->assertCount(0, $rendered->crawler()->filter('dialog'));
    }

    /** The operator edits the roster in the order they read it on screen, so the modal must not resort it. */
    #[Test]
    public function openingTheBatchListsEveryPlayerInTheGamesOwnOrder(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->withEmpire('rome')->persist($this->entityManager);
        $carol = PlayerBuilder::named('Carol')->in($game)->withEmpire('egypt')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('BatchEditor', ['game' => $game])->call('openBatch')->render();

        $this->assertSame(
            [(string) $alice->id, (string) $bob->id, (string) $carol->id],
            $rendered->crawler()->filter('dialog li[data-player-id]')->each(
                static fn ($node): string => (string) $node->attr('data-player-id'),
            ),
        );
    }

    #[Test]
    public function closingTheBatchRemovesTheDialog(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('BatchEditor', ['game' => $game])
            ->call('openBatch')
            ->call('closeBatch')
            ->render();

        $this->assertCount(0, $rendered->crawler()->filter('dialog'));
    }

    #[Test]
    public function eachRowCarriesTheTwoEditableInputsAndOneAstReading(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $row = $this->createLiveComponent('BatchEditor', ['game' => $game])
            ->call('openBatch')
            ->render()
            ->crawler()
            ->filter("li[data-player-id=\"{$player->id}\"]");

        $this->assertSame(
            ["batch-census-{$player->id}", "batch-cities-{$player->id}"],
            $row->filter('input')->each(static fn ($node): string => (string) $node->attr('id')),
        );
        $this->assertCount(1, $row->filter('output'));
    }

    #[Test]
    public function eachRowCarriesItsPlayersEmpire(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('rome')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('BatchEditor', ['game' => $game])->call('openBatch')->render();

        $this->assertSame(
            ['minoa', 'rome'],
            $rendered->crawler()->filter('dialog li[data-player-id]')->each(
                static fn ($node): string => (string) $node->attr('data-empire'),
            ),
        );
    }

    /**
     * The track is stored from zero but numbered from one on the board; a modal that leaked the
     * storage index would have the operator reading a rank behind the physical game.
     */
    #[Test]
    public function theAstReadingShowsTheOneBasedPositionWhileTheStoreKeepsItZeroBased(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $player->astPosition = 4;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('BatchEditor', ['game' => $game])->call('openBatch')->render();

        $reading = $rendered->crawler()->filter("li[data-player-id=\"{$player->id}\"] output");

        $this->assertSame('5', trim($reading->text()));
        $this->assertSame(4, $this->reloadPlayer($player)->astPosition);
    }

    /**
     * The fields carry no value attribute any more — the model owns them, and a model seeded from
     * nothing is what rendered all thirty-six inputs blank in the browser while the markup
     * assertions stayed green. The seed is the only place a component test can still catch that.
     */
    #[Test]
    public function openingTheBatchSeedsEveryFieldFromWhatTheDatabaseHolds(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->withCities(3)->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->withCities(6)->persist($this->entityManager);
        $alice->census = 8;
        $bob->census = 12;
        $this->entityManager->flush();

        $component = $this->createLiveComponent('BatchEditor', ['game' => $game])->call('openBatch')->component();

        $this->assertSame([
            (string) $alice->id => ['census' => '8', 'cities' => '3'],
            (string) $bob->id => ['census' => '12', 'cities' => '6'],
        ], $component->values);
    }

    #[Test]
    #[DataProvider('provideTheEditableInputsCarryTheirStatsOwnBoundsCases')]
    public function theEditableInputsCarryTheirStatsOwnBounds(string $stat, string $expectedMin, string $expectedMax): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $player->treasury = 20;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('BatchEditor', ['game' => $game])->call('openBatch')->render();

        $input = $rendered->crawler()->filter("input[id=\"batch-{$stat}-{$player->id}\"]");

        $this->assertSame([$expectedMin, $expectedMax], [$input->attr('min'), $input->attr('max')]);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function provideTheEditableInputsCarryTheirStatsOwnBoundsCases(): iterable
    {
        yield 'cities span the whole table limit' => ['cities', '0', '9'];

        yield 'population starts at two and stops at what the treasury leaves of the pool' => ['census', '2', '35'];
    }

    /**
     * Track length is a property of the AST version and the empire's own group, not a constant:
     * a stepper hardcoding sixteen positions would be wrong on every expert game.
     */
    #[Test]
    #[DataProvider('provideTheAstSteppersAreDisabledAtEachEndOfThePlayersOwnTrackCases')]
    public function theAstSteppersAreDisabledAtEachEndOfThePlayersOwnTrack(ASTVersion $astVersion, int $lastPosition): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $game->astVersion = $astVersion;
        $atStart = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $oneShortOfTheEnd = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);
        $atEnd = PlayerBuilder::named('Carol')->in($game)->persist($this->entityManager);
        $oneShortOfTheEnd->astPosition = $lastPosition - 1;
        $atEnd->astPosition = $lastPosition;
        $this->entityManager->flush();

        $crawler = $this->createLiveComponent('BatchEditor', ['game' => $game])->call('openBatch')->render()->crawler();

        $this->assertSame(
            [true, false],
            $this->stepperDisabledStatesOf($crawler, $atStart),
        );
        $this->assertSame(
            [false, false],
            $this->stepperDisabledStatesOf($crawler, $oneShortOfTheEnd),
        );
        $this->assertSame(
            [false, true],
            $this->stepperDisabledStatesOf($crawler, $atEnd),
        );
    }

    /** @return iterable<string, array{ASTVersion, int}> */
    public static function provideTheAstSteppersAreDisabledAtEachEndOfThePlayersOwnTrackCases(): iterable
    {
        yield 'a basic track ends at its sixteenth rank' => [ASTVersion::BASIC, 15];

        yield 'an expert track carries one rank more' => [ASTVersion::EXPERT, 16];
    }

    #[Test]
    #[DataProvider('provideSteppingMovesThePlayerOneRankAndStopsAtEachEndOfTheTrackCases')]
    public function steppingMovesThePlayerOneRankAndStopsAtEachEndOfTheTrack(int $startingPosition, int $delta, int $expectedPosition): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $player->astPosition = $startingPosition;
        $this->entityManager->flush();

        $this->createLiveComponent('BatchEditor', ['game' => $game])
            ->call('openBatch')
            ->call('stepAst', ['playerId' => (string) $player->id, 'delta' => $delta]);

        $this->assertSame($expectedPosition, $this->reloadPlayer($player)->astPosition);
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function provideSteppingMovesThePlayerOneRankAndStopsAtEachEndOfTheTrackCases(): iterable
    {
        yield 'forward from the start of the track advances one rank' => [0, 1, 1];

        yield 'backward from the start of the track is refused' => [0, -1, 0];

        yield 'backward from the end of the track retreats one rank' => [15, -1, 14];

        yield 'forward from the end of the track is refused' => [15, 1, 15];
    }

    #[Test]
    #[DataProvider('provideCommitStoresTheTypedValueClampedToTheStatsCeilingCases')]
    public function commitStoresTheTypedValueClampedToTheStatsCeiling(string $stat, string $typed, int $expectedStored): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $this->createLiveComponent('BatchEditor', ['game' => $game])
            ->call('openBatch')
            ->set('values', [(string) $player->id => [$stat => $typed]])
            ->call('commit', ['playerId' => (string) $player->id, 'stat' => $stat]);

        $this->assertSame($expectedStored, $this->reloadPlayer($player)->{$stat});
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function provideCommitStoresTheTypedValueClampedToTheStatsCeilingCases(): iterable
    {
        yield 'a population within the pool is stored verbatim' => ['census', '30', 30];

        yield 'a city count within the table limit is stored verbatim' => ['cities', '7', 7];

        yield 'a city count beyond the nine-city ceiling lands on the ceiling' => ['cities', '42', 9];
    }

    /**
     * The handler clamps but the field does not, so without the re-seed the operator is left
     * staring at the 42 they typed while the game holds 9 — the two readings disagreeing is
     * exactly the state this modal exists to avoid.
     */
    #[Test]
    public function aClampedCommitSettlesTheFieldOnWhatWasStoredRatherThanWhatWasTyped(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $component = $this->createLiveComponent('BatchEditor', ['game' => $game])
            ->call('openBatch')
            ->set('values', [(string) $player->id => ['cities' => '42']])
            ->call('commit', ['playerId' => (string) $player->id, 'stat' => 'cities'])
            ->component();

        $this->assertSame('9', $component->values[(string) $player->id]['cities']);
    }

    /**
     * A cleared <input type="number"> posts an empty string, and (int) '' === 0 would drive the
     * stat to its floor — losing the operator's data on a keystroke they meant to retype.
     */
    #[Test]
    #[DataProvider('provideAClearedFieldLeavesItsStatUntouchedCases')]
    public function aClearedFieldLeavesItsStatUntouched(string $stat, int $storedBefore): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withCities(3)->persist($this->entityManager);
        $player->census = 5;
        $this->entityManager->flush();

        $this->createLiveComponent('BatchEditor', ['game' => $game])
            ->call('openBatch')
            ->set('values', [(string) $player->id => [$stat => '']])
            ->call('commit', ['playerId' => (string) $player->id, 'stat' => $stat]);

        $this->assertSame($storedBefore, $this->reloadPlayer($player)->{$stat});
    }

    /** @return iterable<string, array{string, int}> */
    public static function provideAClearedFieldLeavesItsStatUntouchedCases(): iterable
    {
        yield 'population stays where it was rather than falling to its floor of one' => ['census', 5];

        yield 'cities stay where they were rather than falling to zero' => ['cities', 3];
    }

    /**
     * The modal promises the operator reads what the database holds, and the clamp path below
     * already keeps it. A cleared field left blank would break the same promise in the one
     * direction that looks like data loss.
     */
    #[Test]
    public function aClearedFieldIsRedrawnFromTheStoreRatherThanLeftBlank(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $player->census = 5;
        $this->entityManager->flush();

        $component = $this->createLiveComponent('BatchEditor', ['game' => $game])
            ->call('openBatch')
            ->set('values', [(string) $player->id => ['census' => '']])
            ->call('commit', ['playerId' => (string) $player->id, 'stat' => 'census'])
        ;

        $this->assertSame('5', $component->component()->values[(string) $player->id]['census']);
    }

    #[Test]
    public function aClampedCommitLeavesTheFieldOnTheStoredValueRatherThanTheTypedOne(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $component = $this->createLiveComponent('BatchEditor', ['game' => $game])
            ->call('openBatch')
            ->set('values', [(string) $player->id => ['cities' => '42']])
            ->call('commit', ['playerId' => (string) $player->id, 'stat' => 'cities'])
        ;

        $this->assertSame('9', $component->component()->values[(string) $player->id]['cities']);
    }

    /**
     * playerId travels as a client-supplied argument, so the roster of the component's own game is
     * the only thing standing between the operator's modal and every other table in the database.
     */
    #[Test]
    public function committingAPlayerOfAnotherGameIsIgnored(): void
    {
        $game = GameBuilder::create()->withSlug('the-console-game')->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $otherGame = GameBuilder::create()->withSlug('another-table')->persist($this->entityManager);
        $foreigner = PlayerBuilder::named('Bob')->in($otherGame)->withCities(3)->persist($this->entityManager);

        $this->createLiveComponent('BatchEditor', ['game' => $game])
            ->call('openBatch')
            ->set('values', [(string) $foreigner->id => ['cities' => '9']])
            ->call('commit', ['playerId' => (string) $foreigner->id, 'stat' => 'cities']);

        $this->assertSame(3, $this->reloadPlayer($foreigner)->cities);
    }

    #[Test]
    public function steppingAPlayerOfAnotherGameIsIgnored(): void
    {
        $game = GameBuilder::create()->withSlug('the-console-game')->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $otherGame = GameBuilder::create()->withSlug('another-table')->persist($this->entityManager);
        $foreigner = PlayerBuilder::named('Bob')->in($otherGame)->persist($this->entityManager);

        $this->createLiveComponent('BatchEditor', ['game' => $game])
            ->call('openBatch')
            ->call('stepAst', ['playerId' => (string) $foreigner->id, 'delta' => 1]);

        $this->assertSame(0, $this->reloadPlayer($foreigner)->astPosition);
    }

    /**
     * The stat name is client-supplied too: the batch owns population and cities, and the console
     * deliberately keeps every other stat behind its own picker.
     */
    #[Test]
    #[DataProvider('provideCommitRefusesAStatOutsideTheTwoTheBatchOwnsCases')]
    public function commitRefusesAStatOutsideTheTwoTheBatchOwns(string $stat): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withCities(3)->persist($this->entityManager);
        $player->census = 5;
        $player->treasury = 7;
        $player->astPosition = 4;
        $this->entityManager->flush();

        $this->createLiveComponent('BatchEditor', ['game' => $game])
            ->call('openBatch')
            ->set('values', [(string) $player->id => [$stat => '9']])
            ->call('commit', ['playerId' => (string) $player->id, 'stat' => $stat]);

        $reloaded = $this->reloadPlayer($player);

        $this->assertSame([3, 5, 7, 4], [$reloaded->cities, $reloaded->census, $reloaded->treasury, $reloaded->astPosition]);
    }

    /** @return iterable<string, array{string}> */
    public static function provideCommitRefusesAStatOutsideTheTwoTheBatchOwnsCases(): iterable
    {
        yield 'treasury is a stat, but not one this modal writes' => ['treasury'];

        yield 'the ast position moves by its steppers, never by an input' => ['astPosition'];

        yield 'a name that is no stat at all' => ['thereIsNoSuchStat'];
    }

    /** @return array{bool, bool} the backward then forward stepper's disabled state */
    private function stepperDisabledStatesOf(Crawler $crawler, Player $player): array
    {
        $row = $crawler->filter("li[data-player-id=\"{$player->id}\"]");

        return [
            null !== $row->filter('button[data-live-delta-param="-1"]')->attr('disabled'),
            null !== $row->filter('button[data-live-delta-param="1"]')->attr('disabled'),
        ];
    }

    private function freshEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function reloadPlayer(Player $player): Player
    {
        $reloaded = $this->freshEntityManager()->find(Player::class, $player->id);
        $this->assertInstanceOf(Player::class, $reloaded);

        return $reloaded;
    }
}
