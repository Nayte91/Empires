<?php

declare(strict_types=1);

namespace App\State;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class Game
{
    public const int MAX_SLUG_LENGTH = 64;
    public const int MAX_REGION_LENGTH = 16;

    #[ORM\Id, ORM\Column(type: UuidType::NAME, unique: true)]
    public private(set) Uuid $id;

    #[ORM\Column(length: self::MAX_SLUG_LENGTH, unique: true)]
    public readonly string $slug;

    #[ORM\Column(type: Types::SMALLINT)]
    public int $currentTurn = 1;

    #[ORM\Column(length: self::MAX_REGION_LENGTH, nullable: true, enumType: Region::class)]
    public ?Region $region = Region::West;

    #[ORM\Column(enumType: ASTVersion::class)]
    public ASTVersion $astVersion = ASTVersion::BASIC;

    #[ORM\Column(type: Types::SMALLINT)]
    public int $playerCount = 9;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $finishedAt = null;

    public bool $finished { get => $this->finishedAt instanceof \DateTimeImmutable; }

    /**
     * The cascade is one-directional: deleting a game sweeps its players, deleting a player leaves
     * the game standing. orphanRemoval is deliberately not used.
     *
     * @var Collection<int, Player>
     */
    #[ORM\OneToMany(targetEntity: Player::class, mappedBy: 'game', cascade: ['persist', 'remove'])]
    public Collection $players;

    public function __construct(?string $slug = null)
    {
        $this->id = Uuid::v7();
        $this->slug = $slug ?? (string) $this->id;
        $this->players = new ArrayCollection();
    }

    public function addPlayer(Player $player): void
    {
        if (!$this->players->contains($player)) {
            $this->players->add($player);
        }
    }

    public function removePlayer(Player $player): void
    {
        $this->players->removeElement($player);
    }

    public static function slugify(string $slug): string
    {
        return strtolower((string) new AsciiSlugger()->slug($slug));
    }
}
