<?php

declare(strict_types=1);

namespace App\Tests\Integration\Presentation\Shop;

use App\Presentation\Shop\OrderCardProvider;
use App\Presentation\Shop\OrderCardSort;
use App\State\Game;
use App\State\Order;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\OrderBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\Fixture\Tables;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Userforged\ShopEngine\Service\OrderValidator;

final class OrderCardProviderTest extends WebTestCase
{
    use GameFixtureTrait;

    private OrderCardProvider $orderCardProvider;

    protected function setUp(): void
    {
        $this->initEntityManager();

        $this->orderCardProvider = self::getContainer()->get(OrderCardProvider::class);
    }

    #[Test]
    public function theDeckReadsAsTheOperatorsWorkQueue(): void
    {
        $game = Tables::westTable($this->entityManager);
        OrderBuilder::for(Tables::seat($game, 'Alice'))->onTurn(4)->withKeys('mysticism')->persist($this->entityManager);
        OrderBuilder::for(Tables::seat($game, 'Bob'))->onTurn(4)->withKeys('pottery')->validated()->persist($this->entityManager);
        OrderBuilder::for(Tables::seat($game, 'Dave'))->onTurn(5)->withKeys('pottery')->validated()->persist($this->entityManager);
        OrderBuilder::for(Tables::seat($game, 'Eve'))->onTurn(3)->withKeys('pottery')->validated()->persist($this->entityManager);

        $deck = $this->cardsFor($game);

        $this->assertSame([
            [4, 'Alice', 'pending'],
            [4, 'Carol', 'missing'],
            [4, 'Dave', 'missing'],
            [4, 'Eve', 'missing'],
            [4, 'Bob', 'validated'],
            [5, 'Dave', 'validated'],
            [3, 'Eve', 'validated'],
            [3, 'Alice', 'empty'],
            [3, 'Bob', 'empty'],
            [3, 'Carol', 'empty'],
            [3, 'Dave', 'empty'],
            [2, 'Alice', 'empty'],
            [2, 'Bob', 'empty'],
            [2, 'Carol', 'empty'],
            [2, 'Dave', 'empty'],
            [2, 'Eve', 'empty'],
            [1, 'Alice', 'empty'],
            [1, 'Bob', 'empty'],
            [1, 'Carol', 'empty'],
            [1, 'Dave', 'empty'],
            [1, 'Eve', 'empty'],
        ], array_map(
            static fn (array $card): array => [$card['turn'], $card['player']->name, $card['status']],
            $deck,
        ));
    }

    #[Test]
    public function anOrderOnATurnAfterTheCurrentOneStillGetsACard(): void
    {
        $game = Tables::westTable($this->entityManager);
        $dave = Tables::seat($game, 'Dave');
        OrderBuilder::for($dave)->onTurn(5)->withKeys('pottery')->persist($this->entityManager);

        $card = $this->cardOf($game, $dave, 5);

        $this->assertSame('pending', $card['status']);
        $this->assertSame(['pottery'], $card['slugs']);
    }

    #[Test]
    public function aValidatedCardNamesTheLaterTurnsItsErasureWouldTakeWithIt(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(4)->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(2)->withKeys('pottery')->validated()->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(3)->withKeys('democracy')->validated()->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(4)->withKeys('mysticism')->validated()->persist($this->entityManager);

        $this->assertSame([3, 4], $this->cardOf($game, $player, 2)['alsoErases']);
        $this->assertSame([], $this->cardOf($game, $player, 4)['alsoErases']);
    }

    #[Test]
    public function aPendingCardCarriesTheRecomputedNetCostOfItsLines(): void
    {
        [$game, , $bob] = Tables::aliceAndBob($this->entityManager);
        OrderBuilder::for($bob)->withKeys('pottery')->persist($this->entityManager);

        $card = $this->cardOf($game, $bob, 1);

        $this->assertSame('pending', $card['status']);
        $this->assertSame(['pottery'], $card['slugs']);
        $this->assertSame(60, $card['total']);
        $this->assertSame(1, $card['vp']);
    }

    #[Test]
    public function aValidatedCardCarriesTheTotalItWasFrozenAt(): void
    {
        [$game, , $bob] = Tables::aliceAndBob($this->entityManager);
        $this->validateOrderFor($bob, ['democracy', 'pottery']);

        $card = $this->cardOf($game, $bob, 1);

        $this->assertSame('validated', $card['status']);
        $this->assertSame(280, $card['total']);
        $this->assertSame(7, $card['vp']);
    }

    #[Test]
    public function theCurrentTurnWithNothingSubmittedIsMissingAndWorthNothing(): void
    {
        [$game, , $bob] = Tables::aliceAndBob($this->entityManager);

        $card = $this->cardOf($game, $bob, 1);

        $this->assertSame('missing', $card['status']);
        $this->assertSame([], $card['slugs']);
        $this->assertSame(0, $card['total']);
        $this->assertSame(0, $card['vp']);
    }

    #[Test]
    public function theSeatOfACardIsThePlaceOfItsPlayerAtTheTable(): void
    {
        $game = Tables::westTable($this->entityManager);

        $seatings = array_values(array_unique(array_map(
            static fn (array $card): string => $card['seat'].':'.$card['player']->name,
            $this->cardsFor($game),
        )));
        sort($seatings);

        $this->assertSame(['0:Alice', '1:Bob', '2:Carol', '3:Dave', '4:Eve'], $seatings);
    }

    /** @return list<array{player: Player, seat: int, turn: int, status: string, slugs: list<string>, total: int, vp: int, alsoErases: list<int>}> */
    private function cardsFor(Game $game): array
    {
        return $this->orderCardProvider->cardsFor($game, OrderCardSort::Urgency);
    }

    /** @return array{player: Player, seat: int, turn: int, status: string, slugs: list<string>, total: int, vp: int, alsoErases: list<int>} */
    private function cardOf(Game $game, Player $player, int $turn): array
    {
        foreach ($this->cardsFor($game) as $card) {
            if ($card['turn'] === $turn && $card['player']->id->equals($player->id)) {
                return $card;
            }
        }

        $this->fail(\sprintf('The deck holds no card for %s on turn %d.', $player->name, $turn));
    }

    /** @param list<string> $slugs */
    private function validateOrderFor(Player $player, array $slugs): Order
    {
        $order = OrderBuilder::for($player)->withKeys(...$slugs)->persist($this->entityManager);

        self::getContainer()->get(OrderValidator::class)->validate($order);

        return $order;
    }
}
