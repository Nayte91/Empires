<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Shop;

use App\Presentation\Shop\OrderCardSort;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OrderCardSortTest extends TestCase
{
    private const int CURRENT_TURN = 10;

    /**
     * @param array{int, string, int} $first
     * @param array{int, string, int} $second
     */
    #[Test]
    #[DataProvider('provideUrgencyPutsTheFirstCardOfAPairBeforeTheSecondCases')]
    public function urgencyPutsTheFirstCardOfAPairBeforeTheSecond(array $first, array $second): void
    {
        $player = $this->playerSeatedInAGameOnTurn(self::CURRENT_TURN);

        $asGiven = OrderCardSort::Urgency->compare($this->card($player, ...$first), $this->card($player, ...$second));
        $reversed = OrderCardSort::Urgency->compare($this->card($player, ...$second), $this->card($player, ...$first));

        $this->assertSame(-1, $asGiven <=> 0);
        $this->assertSame(1, $reversed <=> 0);
    }

    public static function provideUrgencyPutsTheFirstCardOfAPairBeforeTheSecondCases(): iterable
    {
        yield 'a submitted order of the current turn comes before nothing submitted at all' => [[self::CURRENT_TURN, 'pending', 0], [self::CURRENT_TURN, 'missing', 0]];

        yield 'nothing submitted on the current turn comes before an already validated one' => [[self::CURRENT_TURN, 'missing', 0], [self::CURRENT_TURN, 'validated', 0]];

        yield 'the validated card of the current turn comes before any future one' => [[self::CURRENT_TURN, 'validated', 0], [11, 'validated', 0]];

        yield 'among filled cards of other turns the future one comes before the past one' => [[11, 'validated', 0], [9, 'validated', 0]];

        yield 'a filled past card comes before an empty one of a later past turn' => [[3, 'validated', 0], [9, 'empty', 0]];

        yield 'within one past turn a submitted order comes before a validated one' => [[9, 'pending', 0], [9, 'validated', 0]];

        yield 'two cards of the same turn and status fall back on the table order' => [[9, 'validated', 0], [9, 'validated', 3]];
    }

    #[Test]
    public function urgencySortsAWholeDeckIntoTheOperatorsWorkQueue(): void
    {
        $player = $this->playerSeatedInAGameOnTurn(self::CURRENT_TURN);
        $deck = [
            $this->card($player, 9, 'empty', 0),
            $this->card($player, self::CURRENT_TURN, 'missing', 1),
            $this->card($player, 11, 'pending', 0),
            $this->card($player, self::CURRENT_TURN, 'pending', 2),
            $this->card($player, self::CURRENT_TURN, 'validated', 0),
            $this->card($player, 3, 'validated', 1),
            $this->card($player, self::CURRENT_TURN, 'pending', 0),
        ];

        usort($deck, OrderCardSort::Urgency->compare(...));

        $this->assertSame([
            [self::CURRENT_TURN, 'pending', 0],
            [self::CURRENT_TURN, 'pending', 2],
            [self::CURRENT_TURN, 'missing', 1],
            [self::CURRENT_TURN, 'validated', 0],
            [11, 'pending', 0],
            [3, 'validated', 1],
            [9, 'empty', 0],
        ], array_map(static fn (array $card): array => [$card['turn'], $card['status'], $card['seat']], $deck));
    }

    private function playerSeatedInAGameOnTurn(int $currentTurn): Player
    {
        return PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn($currentTurn)->build())->build();
    }

    /** @return array{player: Player, seat: int, turn: int, status: string} */
    private function card(Player $player, int $turn, string $status, int $seat): array
    {
        return ['player' => $player, 'seat' => $seat, 'turn' => $turn, 'status' => $status];
    }
}
