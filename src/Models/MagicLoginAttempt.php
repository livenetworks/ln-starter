<?php

namespace LiveNetworks\LnStarter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MagicLoginAttempt extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'email_key',
        'pepper_id',
        'link_token_hash',
        'code_hash',
        'requester_nonce_hash',
        'status',
        'code_attempts',
        'consumed_via',
        'expires_at',
        'consumed_at',
        'code_locked_at',
        'revoked_at',
    ];

    protected $hidden = [
        'email_key',
        'link_token_hash',
        'code_hash',
        'requester_nonce_hash',
    ];

    protected function casts(): array
    {
        return [
            'code_attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'code_locked_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('ln-starter.auth.user_model', 'App\\Models\\User'));
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpired(): bool
    {
        return !$this->expires_at || now()->greaterThanOrEqualTo($this->expires_at);
    }
}
