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
    case WHATSAPP_REPLIED = 'whatsapp_replied';
    case WHATSAPP_OPT_OUT = 'whatsapp_opt_out';
    case WHATSAPP_DELIVERY_FAILED = 'whatsapp_delivery_failed';
    case DOSSIER_BUILT = 'dossier_built';
    case DOSSIER_SOURCE_FAILED = 'dossier_source_failed';
    case DOSSIER_MATCHED = 'dossier_matched';
    case DUPLICATE_EVIDENCE_SKIPPED = 'duplicate_evidence_skipped';
    case AUTO_SENT = 'auto_sent';
    case AUTO_SEND_SKIPPED = 'auto_send_skipped';

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
            self::WHATSAPP_REPLIED => 'Seller respondeu no WhatsApp',
            self::WHATSAPP_OPT_OUT => 'Seller pediu para parar o WhatsApp',
            self::WHATSAPP_DELIVERY_FAILED => 'WhatsApp não entregue',
            self::DOSSIER_BUILT => 'Dossiê automático montado',
            self::DOSSIER_SOURCE_FAILED => 'Fonte do dossiê falhou',
            self::DOSSIER_MATCHED => 'Conferência do dossiê',
            self::DUPLICATE_EVIDENCE_SKIPPED => 'Arquivo repetido ignorado',
            self::AUTO_SENT => 'Enviado automaticamente',
            self::AUTO_SEND_SKIPPED => 'Envio automático não feito',
        };
    }

    public static function label_for(?string $value): string
    {
        return self::tryFrom((string) $value)?->label() ?? (string) $value;
    }
}
