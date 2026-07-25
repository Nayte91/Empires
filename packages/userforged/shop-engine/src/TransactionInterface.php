<?php

declare(strict_types=1);

namespace Userforged\ShopEngine;

/**
 * A single write scope for src/Shop/. No caller currently nests a transactional()
 * call inside another one — SellDirectHandler, for instance, leaves its own
 * mutations unflushed and lets them ride into OrderValidator::validate()'s scope
 * via the unit of work, rather than opening a scope of its own. The re-entrant/
 * joining contract below is not exercised today; it exists so that the day a
 * future caller does wrap another transactional() call, it joins instead of
 * silently nesting a real Doctrine transaction (a SAVEPOINT), where a failure in
 * the inner call would close the EntityManager out from under the outer one.
 */
interface TransactionInterface
{
    /** Runs $unit as one all-or-nothing scope. Re-entrant: a nested call joins the
     *  enclosing scope and does not commit independently.
     *
     *  @param callable(): void $unit */
    public function transactional(callable $unit): void;

    /** Runs $hook once the outermost scope has committed. Runs immediately if no
     *  scope is open. Discarded on rollback.
     *
     *  @param callable(): void $hook */
    public function afterCommit(callable $hook): void;
}
