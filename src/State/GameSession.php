<?php

declare(strict_types=1);

namespace App\State;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class GameSession
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME, unique: true)]
    public private(set) Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    public readonly string $slug;

    #[ORM\Column(type: Types::SMALLINT)]
    public int $currentTurn = 1;

    #[ORM\Column(length: 16, nullable: true)]
    public ?string $region = 'west';

    #[ORM\Column(enumType: ASTVersion::class)]
    public ASTVersion $astVersion = ASTVersion::BASIC;

    #[ORM\Column(type: Types::SMALLINT)]
    public int $playerCount = 9;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $finishedAt = null;

    public bool $finished { get => $this->finishedAt instanceof \DateTimeImmutable; }

    /** @var Collection<int, Player> */
    #[ORM\OneToMany(targetEntity: Player::class, mappedBy: 'game', cascade: ['persist'])]
    public Collection $players;

    public function __construct(?string $slug = null)
    {
        $this->id = Uuid::v7();
        $this->slug = $slug ?? (string) $this->id;
        $this->players = new ArrayCollection();
    }

    /**
     * Scalar mutator for the LiveComponent writable-path mechanism, which
     * requires a scalar and cannot hydrate a BackedEnum directly
     * (see App\Presentation\Component\GameCreator).
     */
    public function setAstVersionValue(string $astVersionValue): void
    {
        $this->astVersion = ASTVersion::from($astVersionValue);
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
}
