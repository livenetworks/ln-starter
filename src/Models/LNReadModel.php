<?php

namespace LiveNetworks\LnStarter\Models;

use Illuminate\Database\Eloquent\Model;
use LiveNetworks\LnStarter\Contracts\ProvidesRecord;

abstract class LNReadModel extends Model implements ProvidesRecord
{
    public $timestamps = false;
    public $incrementing = false;

    /**
     * Read-only model — prevent writes.
     * Backed by database views or read-only tables.
     */
    public function create(array $attributes = [])
    {
        throw new \BadMethodCallException('Cannot create on read-only model: ' . static::class);
    }

    public function update(array $attributes = [], array $options = [])
    {
        throw new \BadMethodCallException('Cannot update a read-only model: ' . static::class);
    }

    public function delete()
    {
        throw new \BadMethodCallException('Cannot delete a read-only model: ' . static::class);
    }

    public function save(array $options = [])
    {
        throw new \BadMethodCallException('Cannot save a read-only model: ' . static::class);
    }

    /**
     * Canonical record shape for data-mode responses. Defaults to the
     * model's cast attribute array; override to coerce/shape fields for
     * the ln-api-connector contract (mirrors toFormPayload()).
     */
    public function toRecord(): array
    {
        return $this->toArray();
    }
}
