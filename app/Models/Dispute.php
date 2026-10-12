<?php

namespace App\Models;

use App\Enums\DisputeStatus;
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
        'complement_request',
        'reviewed_by',
        'evidence_sent_at',
        'closed_at',
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
