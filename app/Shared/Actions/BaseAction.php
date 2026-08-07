<?php

namespace App\Shared\Actions;

/**
 * Base class for single-purpose business actions (e.g. CreateUserAction).
 *
 * Actions encapsulate one unit of work — they are the building blocks used
 * by Services and Controllers. Call an action either as a method
 * (`(new CreateUserAction())->handle($data)`) or as a callable
 * (`app(CreateUserAction::class)($data)`).
 */
abstract class BaseAction
{
    /**
     * Execute the action.
     *
     * @return mixed
     */
    abstract public function handle(): mixed;

    /**
     * Allow actions to be invoked as callables.
     *
     * @return mixed
     */
    public function __invoke(): mixed
    {
        return $this->handle();
    }
}
