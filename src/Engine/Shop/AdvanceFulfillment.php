<?php

declare(strict_types=1);

namespace App\Engine\Shop;

use App\Rules\Ruleset\AdvanceRegistry;
use App\State\CreditEntry;
use App\State\CreditSource;
use App\State\Player;
use App\State\Repository\PlayerRepositoryInterface;
use Symfony\Component\Uid\Uuid;
use Userforged\ShopEngine\FulfillmentInterface;

/**
 * The Game→Shop seam for FulfillmentInterface: delivering means owning the advances and
 * posting their printed credits to the append-only ledger. The library never touches
 * App\State\Player.
 *
 * Every entry is dated with the window the order was bought in, never with the game's
 * clock: an operator validating late must not move the purchase in time.
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
        private PlayerRepositoryInterface $playerRepository,
        private AdvanceRegistry $advanceRegistry,
    ) {}

    public function grant(Uuid $buyerId, array $productKeys, int $window): void
    {
        $player = $this->resolve($buyerId);
        $player->ownAdvances($productKeys);

        foreach ($this->advanceRegistry->getAdvancesByNames($productKeys) as $advance) {
            foreach ($advance->credits as $scope => $value) {
                $player->postCredit(new CreditEntry($window, $scope, $value, CreditSource::Shop, self::SOURCE_PREFIX.$advance->key));
            }
        }
    }

    public function revoke(Uuid $buyerId, array $productKeys): void
    {
        $player = $this->resolve($buyerId);
        $player->disownAdvances($productKeys);

        foreach ($this->advanceRegistry->getAdvancesByNames($productKeys) as $advance) {
            $player->revokeCredits(self::SOURCE_PREFIX.$advance->key);
        }
    }

    private function resolve(Uuid $buyerId): Player
    {
        return $this->playerRepository->findById($buyerId) ?? throw new \RuntimeException('Player not found.');
    }
}
