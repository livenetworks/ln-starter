<?php

namespace LiveNetworks\LnStarter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MagicLinkToken extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'approved',
        'approved_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'approved'    => 'boolean',
            'approved_at' => 'datetime',
            'expires_at'  => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('ln-starter.auth.user_model', 'App\\Models\\User'));
    }

    public function isExpired(): bool
    {
        return now()->isAfter($this->expires_at);
    }

    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->approved;
    }
}
