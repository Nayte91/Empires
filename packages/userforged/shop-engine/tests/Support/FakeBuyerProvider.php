<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests\Support;

use Symfony\Component\Uid\Uuid;
use Userforged\ShopEngine\BuyerInterface;
use Userforged\ShopEngine\BuyerProviderInterface;

final class FakeBuyerProvider implements BuyerProviderInterface
{
    public int $calls = 0;

    /**
     * $onResolve fires at the exact moment the buyer is handed out, which is
     * what makes OrderValidator's ordering invariant observable: a caller can
     * capture the order's status from inside it and prove the buyer was
     * resolved while the order was still Pending.
     */
    public function __construct(
        private readonly BuyerInterface $buyer = new FakeBuyer(),
        private readonly ?\Closure $onResolve = null,
    ) {}

    public function buyerFor(Uuid $buyerId): BuyerInterface
    {
        ++$this->calls;

        $this->onResolve?->__invoke();

        return $this->buyer;
    }
}
