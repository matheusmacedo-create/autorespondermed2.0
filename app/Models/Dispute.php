<?php

namespace App\Models;

use App\Enums\DisputeStatus;
use App\Enums\DossierSource;
use App\Enums\SourceStatus;
use Database\Factories\DisputeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Um MED (ou chargeback) recebido numa conta Woovi - a "ficha" do caso.
 */
class Dispute extends Model
{
    /** @use HasFactory<DisputeFactory> */
    use HasFactory;

    /** Loja do lojista, lida uma vez por instância ({@see merchantStore()}). */
    private ?MerchantStore $merchantStore = null;

    protected $fillable = [
        'woovi_account_id',
        'end_to_end_id',
        'woovi_dispute_id',
        'type',
        'woovi_status',
        'status',
        'value',
        'dispute_reason',
        'situation_type',
        'payer_name',
        'payer_email',
        'payer_phone',
        'opened_at',
        'respond_by',
        'token',
        'token_expires_at',
        'seller_notified_at',
        'seller_opened_at',
        'seller_statement',
        'seller_contact_name',
        'seller_submitted_at',
        'seller_submission_metadata',
        'analyst_notes',
        'ai_defense',
        'ai_defense_generated_at',
        'defense_text',
        'merchant_account_id',
        'merchant',
        'complement_request',
        'reviewed_by',
        'evidence_sent_at',
        'closed_at',
        'dossier_status',
        'dossier_built_at',
        'dossier_match',
        'seller_details',
        'raw',
    ];

    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'status' => DisputeStatus::class,
            'value' => 'integer',
            'opened_at' => 'datetime',
            'respond_by' => 'datetime',
            'token_expires_at' => 'datetime',
            'seller_notified_at' => 'datetime',
            'seller_opened_at' => 'datetime',
            'seller_submitted_at' => 'datetime',
            'seller_submission_metadata' => 'array',
            'evidence_sent_at' => 'datetime',
            'closed_at' => 'datetime',
            'dossier_built_at' => 'datetime',
            'dossier_match' => 'array',
            'seller_details' => 'array',
            'ai_defense' => 'array',
            'merchant' => 'array',
            'ai_defense_generated_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $dispute): void {
            $dispute->token ??= self::newToken();
        });
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }

    /**
     * Conta do lojista na plataforma (accounts.id do unico-api): a da venda do caso
     * e, sem ela, a da conta Woovi (subconta de um seller só).
     */
    public function platformAccountId(): string
    {
        return (string) ($this->merchant_account_id ?: $this->wooviAccount?->unicopag_account_id);
    }

    /**
     * Lojista que recebeu o Pix e é avisado do MED: o da venda (unico-api) e, sem
     * ela, o contato da conta Woovi.
     *
     * @return array{name: ?string, tax_id: ?string, contact_name: ?string, email: ?string, phone: ?string}
     */
    public function seller(): array
    {
        $merchant = (array) ($this->merchant ?? []);
        $account = $this->wooviAccount;

        return [
            'name' => ($merchant['name'] ?? null) ?: $account?->name,
            'tax_id' => ($merchant['tax_id'] ?? null) ?: $account?->tax_id,
            'contact_name' => ($merchant['contact_name'] ?? null) ?: $account?->contact_name,
            'email' => ($merchant['email'] ?? null) ?: $account?->contact_email,
            'phone' => ($merchant['phone'] ?? null) ?: $account?->contact_phone,
        ];
    }

    /**
     * Nome do lojista para a tela: o nome fantasia da venda, depois a razão social
     * da venda. Null quando a venda não identificou o lojista (na conta mestre o
     * nome da conta Woovi seria a própria ÚnicoPag).
     */
    public function merchantName(): ?string
    {
        return data_get($this->merchant, 'trade_name') ?: data_get($this->merchant, 'name') ?: null;
    }

    /**
     * Quando o Pix foi pago: a hora da transação na Woovi, ou a data da venda.
     */
    public function paidAt(): ?Carbon
    {
        $time = data_get($this->sourcePayload(DossierSource::WOOVI_TRANSACTION), 'time')
            ?? data_get($this->sourcePayload(DossierSource::UNICOPAG_SALE), 'paid_at')
            ?? data_get($this->sourcePayload(DossierSource::UNICOPAG_SALE), 'created_at');

        return is_string($time) ? rescue(fn () => Carbon::parse($time), null, report: false) : null;
    }

    /**
     * Produtos do pedido, pela venda na plataforma ("Óculos | Compre 1 Leve 2 (x2)").
     */
    public function products(): ?string
    {
        $items = collect((array) data_get($this->sourcePayload(DossierSource::UNICOPAG_SALE), 'order.items', []))
            ->filter(fn ($item): bool => is_array($item) && filled($item['name'] ?? null))
            ->map(fn (array $item): string => trim((string) $item['name']).((int) ($item['quantity'] ?? 1) > 1 ? ' (x'.(int) $item['quantity'].')' : ''));

        return $items->isEmpty() ? null : mb_substr($items->implode('; '), 0, 300);
    }

    public function merchantStore(): ?MerchantStore
    {
        if (blank($this->merchant_account_id)) {
            return null;
        }

        if ($this->merchantStore?->merchant_account_id !== $this->merchant_account_id) {
            $this->merchantStore = MerchantStore::query()->where('merchant_account_id', $this->merchant_account_id)->first();
        }

        return $this->merchantStore;
    }

    public function wooviAccount(): BelongsTo
    {
        return $this->belongsTo(WooviAccount::class);
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(DisputeEvidence::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DisputeEvent::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(DisputeSource::class);
    }

    /**
     * Payload de uma fonte do dossiê que deu certo (null quando não há).
     *
     * @return array<string, mixed>|null
     */
    public function sourcePayload(DossierSource $source): ?array
    {
        $row = $this->relationLoaded('sources')
            ? $this->sources->first(fn (DisputeSource $s): bool => $s->source === $source)
            : $this->sources()->where('source', $source->value)->first();

        return $row && $row->status === SourceStatus::OK ? (array) $row->payload : null;
    }

    public function whatsappMessages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', DisputeStatus::openValues());
    }

    /**
     * MEDs abertos dentro da janela de trabalho (`med.janela_dias`; 0 = todos).
     *
     * @param  Builder<self>  $query
     */
    public function scopeInWindow(Builder $query): void
    {
        if (($start = self::windowStart()) !== null) {
            $query->where('opened_at', '>=', $start);
        }
    }

    public static function windowStart(): ?Carbon
    {
        $days = (int) config('med.janela_dias');

        return $days > 0 ? now()->subDays($days)->startOfDay() : null;
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    /**
     * Link público do formulário de defesa.
     */
    public function sellerLink(): string
    {
        return route('med.seller', ['token' => $this->token]);
    }

    /**
     * Identificador usado nos endpoints da Woovi. A doc de evidência fala em "id
     * da disputa recebido pelo webhook", mas o payload do webhook não traz `id` e
     * o GET /dispute/{id} documenta o endToEndId - usar o id interno quando a
     * Woovi o devolver, senão o endToEndId.
     */
    public function wooviIdentifier(): string
    {
        return $this->woovi_dispute_id ?: $this->end_to_end_id;
    }

    public function formattedValue(): string
    {
        if ($this->value === null) {
            return '—';
        }

        return 'R$ '.number_format($this->value / 100, 2, ',', '.');
    }

    /**
     * Horas até o fim do prazo (negativo = vencido). Null sem prazo calculado.
     */
    public function hoursLeft(?Carbon $now = null): ?float
    {
        if ($this->respond_by === null) {
            return null;
        }

        return round(($now ?? now())->diffInMinutes($this->respond_by, false) / 60, 1);
    }

    /**
     * Cor do prazo na fila: vermelho perto do fim ou vencido, amarelo no alerta.
     */
    public function deadlineColor(): string
    {
        if ($this->status->isClosed() || $this->status === DisputeStatus::EVIDENCE_SENT) {
            return 'gray';
        }

        $hoursLeft = $this->hoursLeft();

        if ($hoursLeft === null) {
            return 'gray';
        }

        if ($hoursLeft <= config('med.fila.critico_horas_restantes')) {
            return 'danger';
        }

        if ($hoursLeft <= config('med.fila.alerta_horas_restantes')) {
            return 'warning';
        }

        return 'success';
    }
}
