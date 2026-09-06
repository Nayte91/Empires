<?php

declare(strict_types=1);

namespace App\Tests\Integration\Presentation\Shop;

use App\Presentation\Shop\OrderCardProvider;
use App\Presentation\Shop\OrderCardSort;
use App\Rules\Shop\ShopConnector;
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
        $game = $this->westTableOnTurn(8);
        OrderBuilder::for(Tables::seat($game, 'Alice'))->onTurn(8)->withKeys('mysticism')->persist($this->entityManager);
        OrderBuilder::for(Tables::seat($game, 'Bob'))->onTurn(8)->withKeys('pottery')->validated()->persist($this->entityManager);
        OrderBuilder::for(Tables::seat($game, 'Dave'))->onTurn(9)->withKeys('pottery')->validated()->persist($this->entityManager);
        OrderBuilder::for(Tables::seat($game, 'Eve'))->onTurn(7)->withKeys('pottery')->validated()->persist($this->entityManager);

        $deck = $this->cardsFor($game);

        $this->assertSame([
            [8, 'Alice', 'pending'],
            [8, 'Carol', 'missing'],
            [8, 'Dave', 'missing'],
            [8, 'Eve', 'missing'],
            [8, 'Bob', 'validated'],
            [9, 'Dave', 'validated'],
            [7, 'Eve', 'validated'],
            [7, 'Alice', 'empty'],
            [7, 'Bob', 'empty'],
            [7, 'Carol', 'empty'],
            [7, 'Dave', 'empty'],
            [6, 'Alice', 'empty'],
            [6, 'Bob', 'empty'],
            [6, 'Carol', 'empty'],
            [6, 'Dave', 'empty'],
            [6, 'Eve', 'empty'],
        ], array_map(
            static fn (array $card): array => [$card['turn'], $card['player']->name, $card['status']],
            $deck,
        ));
    }

    #[Test]
    public function noCardIsDealtForTheTurnsBeforeTheShopOpens(): void
    {
        $game = $this->westTableOnTurn(ShopConnector::OPENING_TURN - 1);

        $this->assertSame([], $this->cardsFor($game));
    }

    #[Test]
    public function anOrderOnATurnAfterTheCurrentOneStillGetsACard(): void
    {
        $game = $this->westTableOnTurn(8);
        $dave = Tables::seat($game, 'Dave');
        OrderBuilder::for($dave)->onTurn(9)->withKeys('pottery')->persist($this->entityManager);

        $card = $this->cardOf($game, $dave, 9);

        $this->assertSame('pending', $card['status']);
        $this->assertSame(['pottery'], $card['slugs']);
    }

    #[Test]
    public function aValidatedCardNamesTheLaterTurnsItsErasureWouldTakeWithIt(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(8)->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(6)->withKeys('pottery')->validated()->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(7)->withKeys('democracy')->validated()->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(8)->withKeys('mysticism')->validated()->persist($this->entityManager);

        $this->assertSame([7, 8], $this->cardOf($game, $player, 6)['alsoErases']);
        $this->assertSame([], $this->cardOf($game, $player, 8)['alsoErases']);
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
        $game->currentTurn = ShopConnector::OPENING_TURN;
        $this->entityManager->flush();

        $card = $this->cardOf($game, $bob, ShopConnector::OPENING_TURN);

        $this->assertSame('missing', $card['status']);
        $this->assertSame([], $card['slugs']);
        $this->assertSame(0, $card['total']);
        $this->assertSame(0, $card['vp']);
    }

    #[Test]
    public function theSeatOfACardIsThePlaceOfItsPlayerAtTheTable(): void
    {
        $game = $this->westTableOnTurn(ShopConnector::OPENING_TURN);

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

    private function westTableOnTurn(int $turn): Game
    {
        $game = Tables::westTable($this->entityManager);
        $game->currentTurn = $turn;
        $this->entityManager->flush();

        return $game;
    }

    /** @param list<string> $slugs */
    private function validateOrderFor(Player $player, array $slugs): Order
    {
        $order = OrderBuilder::for($player)->withKeys(...$slugs)->persist($this->entityManager);

        self::getContainer()->get(OrderValidator::class)->validate($order);

        return $order;
    }
}
