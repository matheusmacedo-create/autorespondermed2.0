<?php

namespace App\Models;

use App\Enums\Provider;
use App\Enums\WooviAccountSource;
use Database\Factories\WooviAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Conta de um seller na instituição (Woovi ou Transfeera, `provider`; o nome da
 * tabela ficou da primeira). O AppID - na Transfeera, "client_id:client_secret" - é a credencial que lê as disputas e envia
 * as evidências daquela conta - fica criptografado (APP_KEY) e nunca sai na
 * serialização.
 */
class WooviAccount extends Model
{
    /** @use HasFactory<WooviAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'provider',
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
        'auto_send',
        'webhooks_registered_at',
        'last_synced_at',
    ];

    protected $hidden = [
        'app_id',
        'webhook_key',
        'webhook_secret',
    ];

    protected $attributes = [
        'provider' => 'woovi',
    ];

    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'app_id' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'source' => WooviAccountSource::class,
            'is_active' => 'boolean',
            'auto_send' => 'boolean',
            'webhooks_registered_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'evidence_file_id_refused_at' => 'datetime',
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
     * URL que a instituição chama para os eventos de disputa desta conta.
     */
    public function webhookUrl(): string
    {
        return route($this->isTransfeera() ? 'webhooks.transfeera' : 'webhooks.woovi', ['webhookKey' => $this->webhook_key]);
    }

    public function isTransfeera(): bool
    {
        return $this->provider === Provider::TRANSFEERA;
    }
}
