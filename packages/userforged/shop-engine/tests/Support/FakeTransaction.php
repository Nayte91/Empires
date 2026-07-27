<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests\Support;

use Userforged\ShopEngine\TransactionInterface;

/**
 * TransactionInterface's scope semantics without a database: a nested call
 * joins the enclosing scope instead of committing on its own, afterCommit()
 * hooks queue until the outermost scope closes (and run immediately when none
 * is open), and a throwing unit discards the queued hooks.
 *
 * Reproducing all three matters: OrderValidator publishes through
 * afterCommit() precisely so no consumer can observe a validated order before
 * its write landed, and a fake that ran hooks eagerly would let that
 * guarantee rot without a single test turning red.
 */
final class FakeTransaction implements TransactionInterface
{
    public int $committedScopes = 0;

    private int $depth = 0;

    /** @var list<callable(): void> */
    private array $hooks = [];

    public function transactional(callable $unit): void
    {
        ++$this->depth;

        try {
            $unit();
        } catch (\Throwable $e) {
            $this->depth = 0;
            $this->hooks = [];

            throw $e;
        }

        --$this->depth;

        if (0 !== $this->depth) {
            return;
        }

        ++$this->committedScopes;

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
