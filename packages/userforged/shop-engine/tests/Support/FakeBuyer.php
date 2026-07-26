<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests\Support;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;
use Userforged\ShopEngine\BuyerInterface;

final readonly class FakeBuyer implements BuyerInterface
{
    /** @param list<string> $ownedKeys */
    public function __construct(
        public array $ownedKeys = [],
        public Uuid $id = new UuidV4(),
    ) {}
}
