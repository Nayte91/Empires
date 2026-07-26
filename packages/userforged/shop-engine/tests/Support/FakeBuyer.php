<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests\Support;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;
use Userforged\ShopEngine\BuyerInterface;

final readonly class FakeBuyer implements BuyerInterface
{
    /**
     * @param list<string>       $ownedKeys
     * @param array<string, int> $electiveCredits
     */
    public function __construct(
        public array $ownedKeys = [],
        public array $electiveCredits = [],
        public Uuid $id = new UuidV4(),
    ) {}
}
