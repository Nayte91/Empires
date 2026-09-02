<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;

/**
 * A class needing more than the EntityManager declares its own setUp() — class methods beat trait
 * methods — and opens it with initEntityManager().
 */
trait GameFixtureTrait
{
    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        $this->initEntityManager();
    }

    private function initEntityManager(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }
}
