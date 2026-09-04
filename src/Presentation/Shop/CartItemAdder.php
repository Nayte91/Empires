<?php

declare(strict_types=1);

namespace App\Presentation\Shop;

use App\State\Player;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Exception\ShopExceptionReason;

final readonly class CartItemAdder
{
    public function __construct(
        private CartStorageInterface $cartStorage,
        private ShopExceptionTranslator $shopExceptionTranslator,
    ) {}

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
