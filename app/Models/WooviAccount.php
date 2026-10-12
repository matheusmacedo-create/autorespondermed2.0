<?php

namespace App\Models;

use App\Enums\WooviAccountSource;
use Database\Factories\WooviAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Conta Woovi de um seller. O AppID é a credencial que lê as disputas e envia
 * as evidências daquela conta - fica criptografado (APP_KEY) e nunca sai na
 * serialização.
 */
class WooviAccount extends Model
{
    /** @use HasFactory<WooviAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'tax_id',
        'unicopag_account_id',
        'woovi_company_id',
        'app_id',
        'source',
        'webhook_key',
        'contact_name',
        'contact_email',
        'contact_phone',
        'is_active',
        'webhooks_registered_at',
        'last_synced_at',
    ];

    protected $hidden = [
        'app_id',
        'webhook_key',
    ];

    protected function casts(): array
    {
        return [
            'app_id' => 'encrypted',
            'source' => WooviAccountSource::class,
            'is_active' => 'boolean',
            'webhooks_registered_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $account): void {
            $account->webhook_key ??= Str::random(40);
        });
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    /**
     * URL que a Woovi chama para os eventos de disputa desta conta.
     */
    public function webhookUrl(): string
    {
        return route('webhooks.woovi', ['webhookKey' => $this->webhook_key]);
    }
}
