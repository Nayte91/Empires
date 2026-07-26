<?php

declare(strict_types=1);

namespace App\Game\Shop;

use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Repository\PlayerRepository;
use Symfony\Component\Uid\Uuid;
use Userforged\ShopEngine\FulfillmentInterface;

/**
 * The Game→Shop seam for FulfillmentInterface: in Empires, "delivering" an
 * order means adding the purchased slugs to the player's owned advances, and
 * posting each advance's printed credits to the player's append-only credit
 * ledger (Player::postCredit()) — one entry per credits-block entry, at the
 * player's current turn, reasoned by the advance's key. Resolves the buyer id
 * to a Player via PlayerRepository — the library never touches
 * App\Entity\Player itself.
 *
 * revoke() posts the exact negative counterpart of what grant() posted — a
 * symmetry that is non-negotiable, but each negative entry is capped at the
 * scope's current ledger balance (never below zero): writing the uncapped
 * negative would let it sit dormant and silently swallow that scope's future
 * gains once the balance recovers. See Player::postCredit()'s docblock for
 * why the ledger can only ever be appended to, never replaced.
 */
final readonly class AdvanceFulfillment implements FulfillmentInterface
{
    private const string SOURCE_PREFIX = 'advance:';

    public function __construct(
        private PlayerRepository $playerRepository,
        private AdvanceCatalog $advanceCatalog,
    ) {}

    public function grant(Uuid $buyerId, array $productKeys): void
    {
        $player = $this->resolve($buyerId);
        $player->ownAdvances($productKeys);

        foreach ($this->advanceCatalog->getAdvancesByNames($productKeys) as $advance) {
            foreach ($advance->credits as $scope => $value) {
                $player->postCredit($player->game->currentTurn, $scope, $value, self::SOURCE_PREFIX.$advance->key);
            }
        }
    }

    public function revoke(Uuid $buyerId, array $productKeys): void
    {
        $player = $this->resolve($buyerId);
        $player->disownAdvances($productKeys);

        foreach ($this->advanceCatalog->getAdvancesByNames($productKeys) as $advance) {
            foreach ($advance->credits as $scope => $value) {
                $available = $this->balanceFor($player->creditLedger, $scope);
                $player->postCredit($player->game->currentTurn, $scope, -min($value, $available), self::SOURCE_PREFIX.$advance->key);
            }
        }
    }

    private function resolve(Uuid $buyerId): Player
    {
        return $this->playerRepository->find($buyerId) ?? throw new \RuntimeException('Player not found.');
    }

    /** @param list<array{turn: int, scope: string, value: int, reason: string}> $creditLedger */
    private function balanceFor(array $creditLedger, string $scope): int
    {
        $balance = 0;

        foreach ($creditLedger as $entry) {
            if ($scope === $entry['scope']) {
                $balance += $entry['value'];
            }
        }

        return $balance;
    }
}
