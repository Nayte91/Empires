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

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
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
            $this->slug = self::slugify($value);
        }
    }

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Game::class, inversedBy: 'players')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public readonly Game $game,
        string $name,
        // 30 matches MAX_NAME_LENGTH by coincidence only — an empire identifier has nothing to do
        // with a player's name bound. Do not collapse this into the same constant.
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

    /**
     * The single derivation of a player's slug, truncation included — callable without an instance
     * because a validator normalizer (GameCreator, PlayerBoard) has no Player to hand. Truncating
     * here, not just at persistence, is what makes those callers' duplicate/blank checks agree with
     * what actually gets stored: transliteration expands rather than shortens (30 characters of CJK
     * slugify to 119 of pinyin), so a name honouring the one limit players are told about can still
     * produce a longer slug, cut back to that same limit. A collision this causes is the collision
     * two names slugifying alike already produce, which this game treats as genuine, not an
     * accident. The $name hook above calls this same method, so no divergence is possible between
     * what gets persisted and what gets checked.
     */
    public static function slugify(string $name): string
    {
        return mb_substr(strtolower((string) new AsciiSlugger()->slug($name)), 0, self::MAX_NAME_LENGTH);
    }
}
