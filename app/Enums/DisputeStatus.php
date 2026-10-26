<?php

namespace App\Enums;

/**
 * Status interno do caso (coluna disputes.status).
 *
 * Fluxo: pending_seller (MED chegou, seller avisado) -> seller_submitted (seller
 *        mandou a defesa, fila do analista) -> evidence_sent (enviado à Woovi)
 *        -> accepted | rejected | canceled (decisão da Woovi/Bacen).
 *
 * O analista pode devolver de seller_submitted para pending_seller pedindo
 * complemento. A decisão final vem da Woovi (webhook ou sincronização):
 * `accepted` = a devolução foi aceita (o dinheiro volta ao pagador, o seller
 * perdeu); `rejected` = a contestação foi recusada (o seller fica com o valor).
 */
enum DisputeStatus: string
{
    case PENDING_SELLER = 'pending_seller';
    case SELLER_SUBMITTED = 'seller_submitted';
    case EVIDENCE_SENT = 'evidence_sent';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case CANCELED = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_SELLER => 'Aberto',
            self::SELLER_SUBMITTED => 'Lojista respondeu',
            self::EVIDENCE_SENT => 'Enviado à Woovi',
            self::ACCEPTED => 'Devolução aceita',
            self::REJECTED => 'Contestação rejeitada',
            self::CANCELED => 'Cancelado',
        };
    }

    /**
     * Cor do badge no Filament.
     */
    public function color(): string
    {
        return match ($this) {
            self::PENDING_SELLER => 'warning',
            self::SELLER_SUBMITTED => 'info',
            self::EVIDENCE_SENT => 'primary',
            self::ACCEPTED => 'danger',
            self::REJECTED => 'success',
            self::CANCELED => 'gray',
        };
    }

    public function isClosed(): bool
    {
        return in_array($this, self::closed(), true);
    }

    /**
     * Status em que o seller ainda pode mandar (ou completar) a defesa pelo link.
     */
    public function acceptsSellerSubmission(): bool
    {
        return $this === self::PENDING_SELLER;
    }

    /**
     * Status em que o caso ainda pode ser enviado à Woovi.
     */
    public function canSendToWoovi(): bool
    {
        return in_array($this, [self::PENDING_SELLER, self::SELLER_SUBMITTED, self::EVIDENCE_SENT], true);
    }

    /**
     * @return array<int, self>
     */
    public static function closed(): array
    {
        return [self::ACCEPTED, self::REJECTED, self::CANCELED];
    }

    /**
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return [
            self::PENDING_SELLER->value,
            self::SELLER_SUBMITTED->value,
            self::EVIDENCE_SENT->value,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }

    /**
     * Status final a partir do status da Woovi. Retorna null quando a Woovi
     * ainda não decidiu (OPENED, IN_REVIEW, PENDING...): nesse caso o status
     * interno segue o fluxo de resposta e não é tocado.
     */
    public static function fromWooviStatus(?string $wooviStatus): ?self
    {
        return match (strtoupper((string) $wooviStatus)) {
            'ACCEPTED' => self::ACCEPTED,
            'REJECTED' => self::REJECTED,
            'CANCELED', 'CANCELLED' => self::CANCELED,
            default => null,
        };
    }
}
