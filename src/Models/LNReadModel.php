<?php

namespace LiveNetworks\LnStarter\Models;

use Illuminate\Database\Eloquent\Model;

abstract class LNReadModel extends Model
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
}
