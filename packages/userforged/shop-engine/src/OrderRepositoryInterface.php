<?php

declare(strict_types=1);

namespace Userforged\ShopEngine;

use Symfony\Component\Uid\Uuid;

interface OrderRepositoryInterface
{
    public function findOneByBuyerAndWindow(Uuid $buyerId, int $window): ?OrderInterface;

    /**
     * A new empty Pending order, registered with the current unit of work.
     * NOT durable until the enclosing transactional scope commits.
     */
    public function create(Uuid $buyerId, int $window): OrderInterface;

    /** Schedules deletion in the current unit of work. Same durability contract as create(). */
    public function remove(OrderInterface $order): void;
}
