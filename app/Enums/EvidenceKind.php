<?php

namespace App\Enums;

/**
 * Tipo de evidência que o seller (ou o analista) anexa à defesa. O label vai na
 * `description` do documento enviado à Woovi, então precisa se explicar sozinho
 * para quem analisa do outro lado.
 */
enum EvidenceKind: string
{
    case INVOICE = 'invoice';
    case DELIVERY_PROOF = 'delivery_proof';
    case SERVICE_PROOF = 'service_proof';
    case CONVERSATION = 'conversation';
    case CONTRACT = 'contract';
    case PAYER_IDENTIFICATION = 'payer_identification';
    case DEFENSE_LETTER = 'defense_letter';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::INVOICE => 'Nota fiscal ou recibo da venda',
            self::DELIVERY_PROOF => 'Comprovante de entrega (rastreio, assinatura, foto)',
            self::SERVICE_PROOF => 'Comprovante de prestação do serviço',
            self::CONVERSATION => 'Conversa com o comprador (WhatsApp, e-mail, chat)',
            self::CONTRACT => 'Contrato, pedido ou termo aceito pelo comprador',
            self::PAYER_IDENTIFICATION => 'Identificação do comprador (cadastro, documento)',
            self::DEFENSE_LETTER => 'Carta de defesa do recebedor',
            self::OTHER => 'Outro documento',
        };
    }

    /**
     * Opções que o seller vê no formulário (a carta de defesa é gerada pelo sistema).
     *
     * @return array<string, string>
     */
    public static function sellerOptions(): array
    {
        return collect(self::cases())
            ->reject(fn (self $kind): bool => $kind === self::DEFENSE_LETTER)
            ->mapWithKeys(fn (self $kind): array => [$kind->value => $kind->label()])
            ->all();
    }

    public static function label_for(?string $value): string
    {
        return self::tryFrom((string) $value)?->label() ?? (string) $value;
    }
}
