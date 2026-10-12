<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chamada crua do webhook da Woovi, gravada antes de qualquer processamento.
 */
class WooviWebhookCall extends Model
{
    protected $fillable = [
        'woovi_account_id',
        'event',
        'signature_valid',
        'payload',
        'headers',
        'dispute_id',
        'processed_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'headers' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function wooviAccount(): BelongsTo
    {
        return $this->belongsTo(WooviAccount::class);
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedPayload(): array
    {
        $decoded = json_decode($this->payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
