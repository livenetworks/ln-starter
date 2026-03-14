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
        return false;
    }

    public function update(array $attributes = [], array $options = [])
    {
        return false;
    }

    public function delete()
    {
        return false;
    }

    public function save(array $options = [])
    {
        return false;
    }
}
