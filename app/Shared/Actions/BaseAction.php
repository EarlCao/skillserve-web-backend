<?php

namespace App\Shared\Actions;

/**
 * Base class for single-purpose business actions (e.g. CreateUserAction).
 *
 * Actions encapsulate one unit of work — they are the building blocks used
 * by Services and Controllers. Concrete actions declare their own typed
 * `handle(...)` signature, because a fixed abstract signature would prevent
 * actions from requiring their own parameters (PHP method compatibility
 * forbids adding required parameters in an override):
 *
 *     final class CreateUserAction extends BaseAction
 *     {
 *         public function handle(array $data): User { ... }
 *     }
 *
 * Resolve through the container and invoke directly:
 *
 *     app(CreateUserAction::class)->handle($data);
 */
abstract class BaseAction {}
