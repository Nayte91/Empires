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
 * revoke() removes every ledger entry reasoned by the advance's key
 * (Player::revokeCredits()) instead of posting a negative counterpart:
 * cancelling an order is a correction of a mis-entry, not a game fact, so the
 * trace is erased rather than compensated. Discriminating on the reason alone
 * is deliberate — it sweeps up everything that advance ever produced in one
 * pass, including anything a later capability trigger may have posted under
 * the same reason.
 *
 * Removing an entry outright can never drive a scope's balance negative:
 * ShopConnector::ledgerEntitlements() replays the remaining entries
 * chronologically and caps each withdrawal against what is available at that
 * point in the walk, not against what was available when it was written.
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
                $player->postCredit(new CreditEntry($player->game->currentTurn, $scope, $value, CreditSource::Shop, self::SOURCE_PREFIX.$advance->key));
            }
        }
    }

    public function revoke(Uuid $buyerId, array $productKeys): void
    {
        $player = $this->resolve($buyerId);
        $player->disownAdvances($productKeys);

        foreach ($this->advanceCatalog->getAdvancesByNames($productKeys) as $advance) {
            $player->revokeCredits(self::SOURCE_PREFIX.$advance->key);
        }
    }

    private function resolve(Uuid $buyerId): Player
    {
        return $this->playerRepository->find($buyerId) ?? throw new \RuntimeException('Player not found.');
    }
}
