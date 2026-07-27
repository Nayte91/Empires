<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests\Support;

use Symfony\Component\Uid\Uuid;
use Userforged\ShopEngine\FulfillmentInterface;

final class FakeFulfillment implements FulfillmentInterface
{
    /** @var list<list<string>> */
    public array $granted = [];

    /** @var list<list<string>> */
    public array $revoked = [];

    /** @param list<string> $productKeys */
    public function grant(Uuid $buyerId, array $productKeys): void
    {
        $this->granted[] = $productKeys;
    }

    /** @param list<string> $productKeys */
    public function revoke(Uuid $buyerId, array $productKeys): void
    {
        $this->revoked[] = $productKeys;
    }
}
