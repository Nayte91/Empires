<?php

declare(strict_types=1);

namespace App\Game\Shop;

use App\Entity\Player;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Exception\ShopExceptionReason;

/**
 * The guard sequence both cart hosts run before an advance may join a cart —
 * App\Component\Shop for the player's own kiosk, App\Component\PlayerOrders
 * for the operator's POS ticket. The two differ only on which cart they name
 * (see CartKey); Shop's extra turn-lock stays on Shop, since the POS is
 * precisely the console that is allowed to work past it.
 */
final readonly class CartItemAdder
{
    public function __construct(
        private CartStorageInterface $cartStorage,
        private ShopExceptionTranslator $shopExceptionTranslator,
    ) {}

    /**
     * The player-facing refusal message, or null when the advance joined the
     * cart — or was already in it, re-adding being a silent no-op.
     */
    public function add(Player $player, string $storageKey, string $key): ?string
    {
        if (\in_array($key, $player->advances, true)) {
            return $this->shopExceptionTranslator->messageForReason(ShopExceptionReason::ProductAlreadyOwned, ['key' => $key]);
        }

        $cart = $this->cartStorage->load($storageKey);

        if ($cart->has($key)) {
            return null;
        }

        $cart->add($key);
        $this->cartStorage->save($storageKey, $cart);

        return null;
    }
}
