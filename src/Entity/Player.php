<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\CreditLedgerType;
use App\Game\Shop\CreditEntry;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_player_game_slug', columns: ['game_id', 'slug'])]
class Player
{
    public const int CENSUS_MIN = 0;
    public const int CENSUS_MAX = 55;
    public const int TREASURY_MIN = 0;
    public const int TREASURY_MAX = 55;
    public const int SHIPS_MIN = 0;
    public const int SHIPS_MAX = 4;
    public const int CARDS_MIN = 0;
    public const int AST_MIN = 0;

    // REFACTOR-WHEN: track_length in ast.yaml diverges from 16 — AST_MAX duplicates track_length-1
    // (same established pattern as CENSUS_MAX vs max_population); bind entity clamps to config in
    // one move when any of them diverges.
    public const int AST_MAX = 15;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public private(set) Uuid $id;

    #[ORM\Column(length: 64)]
    public private(set) string $slug; // @phpstan-ignore property.uninitialized (always assigned by the $name hook, itself always set in the constructor)

    #[ORM\Column(length: 30, nullable: true)]
    public ?string $empire = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    public private(set) array $advances = [];

    /** @var list<CreditEntry> */
    #[ORM\Column(type: CreditLedgerType::NAME, options: ['default' => '[]'])]
    public private(set) array $creditLedger = [];

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    public int $cities = 0 {
        set => max(0, min(9, $value));
    }

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    public int $census = 1 {
        set => max(self::CENSUS_MIN, min(self::CENSUS_MAX, $value));
    }

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    public int $treasury = 0 {
        set => max(self::TREASURY_MIN, min(self::TREASURY_MAX, $value));
    }

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    public int $ships = 0 {
        set => max(self::SHIPS_MIN, min(self::SHIPS_MAX, $value));
    }

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    public int $cards = 0 {
        set => max(self::CARDS_MIN, $value);
    }

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    public int $astPosition = 0 {
        set => max(self::AST_MIN, min(self::AST_MAX, $value));
    }

    #[ORM\Column(length: 50)]
    public string $name {
        set {
            $this->name = $value;
            $this->slug = $this->slugify($value);
        }
    }

    public function __construct(
        #[ORM\ManyToOne(targetEntity: GameSession::class, inversedBy: 'players')]
        #[ORM\JoinColumn(nullable: false)]
        public readonly GameSession $game,
        string $name,
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
