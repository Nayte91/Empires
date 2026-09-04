<?php

declare(strict_types=1);

namespace App\State;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_player_game_slug', columns: ['game_id', 'slug'])]
class Player
{
    public const int MAX_NAME_LENGTH = 20;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public private(set) Uuid $id;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    public private(set) string $slug; // @phpstan-ignore property.uninitialized (always assigned by the $name hook, itself always set in the constructor)

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    public private(set) array $advances = [];

    /** @var list<CreditEntry> */
    #[ORM\Column(type: 'credit_ledger', options: ['default' => '[]'])]
    public private(set) array $creditLedger = [];

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    public int $cities = 0;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    public int $census = 1;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    public int $treasury = 0;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    public int $ships = 0;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    public int $cards = 0;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    public int $astPosition = 0;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    public string $name {
        set {
            $this->name = $value;
            $this->slug = self::slugify($value);
        }
    }

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Game::class, inversedBy: 'players')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public readonly Game $game,
        string $name,
        #[ORM\Column(length: 30)]
        public string $empire,
    ) {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->game->addPlayer($this);
    }

    /** @param list<string> $keys */
    public function ownAdvances(array $keys): void
    {
        $this->advances = array_values(array_unique([...$this->advances, ...$keys]));
    }

    /** @param list<string> $keys */
    public function disownAdvances(array $keys): void
    {
        $this->advances = array_values(array_diff($this->advances, $keys));
    }

    public function postCredit(CreditEntry $entry): void
    {
        $this->creditLedger = [...$this->creditLedger, $entry];
    }

    public function revokeCredits(string $reason): void
    {
        $this->creditLedger = array_values(array_filter(
            $this->creditLedger,
            static fn (CreditEntry $entry): bool => $reason !== $entry->reason,
        ));
    }

    public static function slugify(string $name): string
    {
        return mb_substr(strtolower((string) new AsciiSlugger()->slug($name)), 0, self::MAX_NAME_LENGTH);
    }
}
