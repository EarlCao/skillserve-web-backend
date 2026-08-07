<?php

namespace App\Shared\Traits;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Provides a transaction helper for services and actions that write data.
 */
trait HandlesTransactions
{
    /**
     * Run a callback inside a database transaction.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws Throwable
     */
    protected function transaction(callable $callback, int $attempts = 1): mixed
    {
        return DB::transaction($callback, $attempts);
    }
}
