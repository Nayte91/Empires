<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests\Support;

use Symfony\Component\Uid\Uuid;
use Userforged\ShopEngine\FulfillmentInterface;

final class FakeFulfillment implements FulfillmentInterface
{
    /** @var list<list<string>> */
    public array $granted = [];

    /** @var list<int> */
    public array $grantedWindows = [];

    /** @var list<list<string>> */
    public array $revoked = [];

    /** @param list<string> $productKeys */
    public function grant(Uuid $buyerId, array $productKeys, int $window): void
    {
        $this->granted[] = $productKeys;
        $this->grantedWindows[] = $window;
    }

    /** @param list<string> $productKeys */
    public function revoke(Uuid $buyerId, array $productKeys): void
    {
        $this->revoked[] = $productKeys;
    }
}
