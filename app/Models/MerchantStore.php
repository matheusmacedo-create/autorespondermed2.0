<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A loja de um lojista (conta da plataforma) e as políticas capturadas do site.
 */
class MerchantStore extends Model
{
    public const KINDS = [
        'shipping' => 'Política de entrega',
        'terms' => 'Termos de uso',
        'exchange' => 'Política de trocas e devoluções',
    ];

    protected $fillable = [
        'merchant_account_id',
        'name',
        'website',
        'website_source',
        'shipping_url',
        'terms_url',
        'exchange_url',
    ];

    protected function casts(): array
    {
        return [
            'captures' => 'array',
            'captured_at' => 'datetime',
            'capture_attempted_at' => 'datetime',
        ];
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class, 'merchant_account_id', 'merchant_account_id');
    }

    /**
     * Captura nova o bastante para reaproveitar (`med.lojas.recapturar_dias`).
     */
    public function isFresh(): bool
    {
        return $this->captured_at !== null
            && $this->captures !== []
            && $this->captured_at->gt(now()->subDays((int) config('med.lojas.recapturar_dias')));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function capture(string $kind): ?array
    {
        return collect((array) $this->captures)->firstWhere('kind', $kind);
    }
}
