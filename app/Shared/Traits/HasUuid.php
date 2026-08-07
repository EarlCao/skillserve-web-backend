<?php

namespace App\Shared\Traits;

use Illuminate\Support\Str;

/**
 * Adds a UUID column to a model.
 *
 * Usage:
 *   use HasUuid;
 *
 *   protected $uuidColumn = 'uuid'; // override if your column is named differently
 *
 * If the UUID column is also the primary key, key behavior switches to
 * string, non-incrementing automatically.
 */
trait HasUuid
{
    /**
     * Boot the trait: fill the UUID before creating.
     */
    protected static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getUuidColumn()})) {
                $model->{$model->getUuidColumn()} = (string) Str::uuid();
            }
        });
    }

    /**
     * Name of the UUID column.
     */
    public function getUuidColumn(): string
    {
        return $this->uuidColumn ?? 'uuid';
    }

    /**
     * Non-incrementing only when the UUID column is the primary key.
     */
    public function getIncrementing(): bool
    {
        return $this->primaryKey !== $this->getUuidColumn();
    }

    /**
     * String key only when the UUID column is the primary key.
     */
    public function getKeyType(): string
    {
        return $this->primaryKey === $this->getUuidColumn() ? 'string' : 'int';
    }
}
