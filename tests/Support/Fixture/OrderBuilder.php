<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixture;

use App\State\Order;
use App\State\Player;
use Doctrine\ORM\EntityManagerInterface;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;

/** A bare OrderBuilder::for($player)->build() is a pending, empty order on the game's current turn. */
final class OrderBuilder
{
    private ?int $turn = null;

    /** @var list<OrderLine> */
    private array $lines = [];

    private ?OrderStatus $status = null;
    private int $total = 0;

    private function __construct(private readonly Player $player) {}

    public static function for(Player $player): self
    {
        return new self($player);
    }

    public function onTurn(int $turn): self
    {
        $this->turn = $turn;

        return $this;
    }

    public function withKeys(string ...$slugs): self
    {
        foreach ($slugs as $slug) {
            $this->lines[] = new OrderLine($slug, 0);
        }

        return $this;
    }

    public function withLine(OrderLine $line): self
    {
        $this->lines[] = $line;

        return $this;
    }

    /**
     * Also hands the advances to the player, as AdvanceFulfillment does; it does not post the
     * printed credits.
     */
    public function validated(int $total = 0): self
    {
        $this->status = OrderStatus::Validated;
        $this->total = $total;

        return $this;
    }

    /**
     * A pending order carrying a total, which production never produces: freeze() writes the total
     * and also validates.
     */
    public function frozenAsPending(int $total): self
    {
        $this->status = OrderStatus::Pending;
        $this->total = $total;

        return $this;
    }

    public function build(): Order
    {
        $order = new Order($this->player, $this->turn ?? $this->player->game->currentTurn);

        if (!$this->status instanceof OrderStatus) {
            $order->replaceLines($this->lines);

            return $order;
        }

        // freeze() refuses to run twice and reads validatedAt, not the status — hence the marking after it.
        $order->freeze($this->lines, $this->total);
        $order->setMarking($this->status->value);

        if (OrderStatus::Validated === $this->status) {
            $this->player->ownAdvances($order->keys());
        }

        return $order;
    }

    public function persist(EntityManagerInterface $entityManager): Order
    {
        $order = $this->build();

        $entityManager->persist($order);
        $entityManager->flush();

        return $order;
    }
}
