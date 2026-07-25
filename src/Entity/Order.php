<?php

declare(strict_types=1);

namespace App\Entity;

use App\Shop\Doctrine\OrderLinesType;
use App\Shop\Dto\OrderLine;
use App\Shop\Exception\OrderException;
use App\Shop\OrderStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'orders')]
#[ORM\UniqueConstraint(name: 'uniq_player_turn', columns: ['player_id', 'turn'])]
class Order
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public private(set) Uuid $id;

    #[ORM\Column(enumType: OrderStatus::class)]
    public private(set) OrderStatus $status = OrderStatus::Pending;

    /** @var list<OrderLine> */
    #[ORM\Column(type: OrderLinesType::NAME)]
    public private(set) array $lines = [];

    #[ORM\Column(nullable: true)]
    public private(set) ?int $total = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $validatedAt = null;

    #[ORM\Column]
    public readonly \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Player::class)]
        #[ORM\JoinColumn(nullable: false)]
        public readonly Player $player,
        #[ORM\Column(type: Types::SMALLINT)]
        public readonly int $turn,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    /** @param list<OrderLine> $lines */
    public function replaceLines(array $lines): void
    {
        if (OrderStatus::Validated === $this->status) {
            throw OrderException::linesLocked();
        }

        $this->lines = $lines;
    }

    /**
     * @param list<OrderLine> $lines
     *
     * Defense in depth: the `validate` transition only ever reaches a pending order
     * (enforced by the workflow), so `validatedAt` — not `status`, already flipped by
     * the state machine by the time this runs — is what detects a re-entrant freeze
     */
    public function freeze(array $lines, int $total): void
    {
        if ($this->validatedAt instanceof \DateTimeImmutable) {
            throw OrderException::alreadyValidated();
        }

        $this->lines = $lines;
        $this->total = $total;
        $this->validatedAt = new \DateTimeImmutable();
    }

    /** Workflow marking store access — status writes go through the shop_order state machine. */
    public function getMarking(): string
    {
        return $this->status->value;
    }

    /**
     * Workflow marking store access — status writes go through the shop_order state machine.
     *
     * @param array<string, mixed> $context
     */
    public function setMarking(string $marking, array $context = []): void
    {
        $this->status = OrderStatus::from($marking);
    }

    /** @return list<OrderLine> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(static fn (OrderLine $line): string => $line->key, $this->lines);
    }
}
