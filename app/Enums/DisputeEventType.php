<?php

namespace App\Enums;

/**
 * Tipos de evento da linha do tempo do caso (coluna dispute_events.event).
 */
enum DisputeEventType: string
{
    case RECEIVED = 'received';
    case WOOVI_STATUS_CHANGED = 'woovi_status_changed';
    case SELLER_NOTIFIED = 'seller_notified';
    case SELLER_NOTIFICATION_FAILED = 'seller_notification_failed';
    case SELLER_OPENED_LINK = 'seller_opened_link';
    case SELLER_SUBMITTED = 'seller_submitted';
    case COMPLEMENT_REQUESTED = 'complement_requested';
    case ANALYST_EVIDENCE_ADDED = 'analyst_evidence_added';
    case DEFENSE_LETTER_GENERATED = 'defense_letter_generated';
    case EVIDENCE_SENT = 'evidence_sent';
    case EVIDENCE_SEND_FAILED = 'evidence_send_failed';
    case SYNCED = 'synced';
    case SYNC_FAILED = 'sync_failed';
    case DEADLINE_WARNING = 'deadline_warning';
    case CLOSED = 'closed';
    case WEBHOOK_REJECTED = 'webhook_rejected';

    public function label(): string
    {
        return match ($this) {
            self::RECEIVED => 'MED recebido',
            self::WOOVI_STATUS_CHANGED => 'Status na Woovi mudou',
            self::SELLER_NOTIFIED => 'Seller avisado',
            self::SELLER_NOTIFICATION_FAILED => 'Falha ao avisar o seller',
            self::SELLER_OPENED_LINK => 'Seller abriu o link',
            self::SELLER_SUBMITTED => 'Seller enviou a defesa',
            self::COMPLEMENT_REQUESTED => 'Complemento pedido ao seller',
            self::ANALYST_EVIDENCE_ADDED => 'Analista anexou evidência',
            self::DEFENSE_LETTER_GENERATED => 'Carta de defesa gerada',
            self::EVIDENCE_SENT => 'Evidências enviadas à Woovi',
            self::EVIDENCE_SEND_FAILED => 'Falha ao enviar à Woovi',
            self::SYNCED => 'Sincronizado com a Woovi',
            self::SYNC_FAILED => 'Falha ao sincronizar',
            self::DEADLINE_WARNING => 'Prazo vencendo',
            self::CLOSED => 'Caso encerrado',
            self::WEBHOOK_REJECTED => 'Webhook recusado',
        };
    }

    public static function label_for(?string $value): string
    {
        return self::tryFrom((string) $value)?->label() ?? (string) $value;
    }
}
