<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento da linha do tempo do caso (append-only).
 */
class DisputeEvent extends Model
{
    protected $fillable = [
        'dispute_id',
        'woovi_account_id',
        'event',
        'level',
        'source',
        'message',
        'context',
        'user_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
