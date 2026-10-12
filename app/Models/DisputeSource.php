<?php

namespace App\Models;

use App\Enums\DossierSource;
use App\Enums\SourceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma fonte do dossiê automático de um caso. O payload traz dados do pagador e do
 * comprador: criptografado (APP_KEY) e nunca serializado.
 */
class DisputeSource extends Model
{
    protected $fillable = [
        'dispute_id',
        'source',
        'status',
        'payload',
        'error',
        'attempts',
        'fetched_at',
    ];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'source' => DossierSource::class,
            'status' => SourceStatus::class,
            'payload' => 'encrypted:array',
            'attempts' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }
}
