<?php

namespace App\Shared\Services;

use App\Shared\Traits\HandlesTransactions;

/**
 * Base class for domain services.
 *
 * Business logic belongs in Services (and single-purpose Actions), keeping
 * controllers thin. Services return plain data — never JSON responses.
 */
abstract class BaseService
{
    use HandlesTransactions;
}
