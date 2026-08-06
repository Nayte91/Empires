<?php

declare(strict_types=1);

namespace App\State;

use App\Infrastructure\Doctrine\CreditLedgerType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_player_game_slug', columns: ['game_id', 'slug'])]
class Player
{
    public const int MAX_NAME_LENGTH = 30;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public private(set) Uuid $id;

    #[ORM\Column(length: 64)]
    public private(set) string $slug; // @phpstan-ignore property.uninitialized (always assigned by the $name hook, itself always set in the constructor)

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    public private(set) array $advances = [];

    /** @var list<CreditEntry> */
    #[ORM\Column(type: CreditLedgerType::NAME, options: ['default' => '[]'])]
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
            $this->slug = $this->slugify($value);
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

    /**
     * The ledger receives additions for game facts — a grant, and a genuine loss posted as a
     * negative value. Removal is reserved for the cancellation of an order, which is not a game
     * fact but a correction of a mis-entry. A forfeit is never a removal.
     */
    public function postCredit(CreditEntry $entry): void
    {
        $this->creditLedger = [...$this->creditLedger, $entry];
    }

    /**
     * The counterpart to postCredit() for order cancellation: removes every entry whose reason
     * matches, rather than offsetting them. array_values() keeps the property serializing as a
     * JSON list rather than an object once entries have been filtered out.
     */
    public function revokeCredits(string $reason): void
    {
        $this->creditLedger = array_values(array_filter(
            $this->creditLedger,
            static fn (CreditEntry $entry): bool => $reason !== $entry->reason,
        ));
    }

    private function slugify(string $name): string
    {
        return strtolower((string) new AsciiSlugger()->slug($name));
    }
}
