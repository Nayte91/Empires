<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enumeration\OrderStatus;
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

    /** @var list<array{key: string, netCost: int}>|list<string> */
    #[ORM\Column(type: Types::JSON)]
    public private(set) array $lines = [];

    #[ORM\Column(nullable: true)]
    public private(set) ?int $total = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $money = null;

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

    /** @param list<string> $keys */
    public function replaceLines(array $keys): void
    {
        if (OrderStatus::Validated === $this->status) {
            throw new \DomainException('Cannot replace lines of an already validated order.');
        }

        $this->lines = $keys;
    }

    /** @param list<array{key: string, netCost: int}> $frozenLines */
    public function validate(array $frozenLines, int $total, int $money): void
    {
        if (OrderStatus::Validated === $this->status) {
            throw new \DomainException('Order is already validated.');
        }

        $this->lines = $frozenLines;
        $this->total = $total;
        $this->money = $money;
        $this->validatedAt = new \DateTimeImmutable();
        $this->status = OrderStatus::Validated;
    }
}
