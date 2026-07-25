<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Userforged\ShopEngine\TransactionInterface;

final class DoctrineTransaction implements TransactionInterface
{
    private int $depth = 0;

    /** @var list<callable(): void> */
    private array $hooks = [];

    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function transactional(callable $unit): void
    {
        if ($this->depth > 0) {
            ++$this->depth;

            try {
                $unit();
            } finally {
                --$this->depth;
            }

            return;
        }

        $this->depth = 1;

        try {
            $this->entityManager->wrapInTransaction(static function () use ($unit): void {
                $unit();
            });
        } catch (\Throwable $e) {
            $this->hooks = [];

            throw $e;
        } finally {
            $this->depth = 0;
        }

        $hooks = $this->hooks;
        $this->hooks = [];
        foreach ($hooks as $hook) {
            $hook();
        }
    }

    public function afterCommit(callable $hook): void
    {
        if (0 === $this->depth) {
            $hook();

            return;
        }

        $this->hooks[] = $hook;
    }
}
