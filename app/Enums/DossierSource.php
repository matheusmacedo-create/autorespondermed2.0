<?php

namespace App\Enums;

/**
 * Fontes do dossiê automático, na ordem em que são buscadas: a transação da Woovi
 * vem primeiro porque é ela que liga o endToEndId à cobrança (correlationID) e, por
 * ela, à venda na plataforma.
 */
enum DossierSource: string
{
    case WOOVI_TRANSACTION = 'woovi_transaction';
    case WOOVI_CHARGE = 'woovi_charge';
    case WOOVI_REFUNDS = 'woovi_refunds';
    case WOOVI_RECEIPT = 'woovi_receipt';
    case WOOVI_FUNDS_RECOVERY = 'woovi_funds_recovery';
    case UNICOPAG_SALE = 'unicopag_sale';
    case UNICOPAG_REGISTER = 'unicopag_register';
    case KYC_STATUS = 'kyc_status';

    public function label(): string
    {
        return match ($this) {
            self::WOOVI_TRANSACTION => 'Transação Pix (Woovi)',
            self::WOOVI_CHARGE => 'Cobrança Pix (Woovi)',
            self::WOOVI_REFUNDS => 'Devoluções já feitas (Woovi)',
            self::WOOVI_RECEIPT => 'Comprovante oficial (Woovi)',
            self::WOOVI_FUNDS_RECOVERY => 'Alegação do MED (Woovi, funds-recovery)',
            self::UNICOPAG_SALE => 'Venda na plataforma (unico-api)',
            self::UNICOPAG_REGISTER => 'Cadastro do seller (unico-api)',
            self::KYC_STATUS => 'KYC do seller',
        };
    }

    public static function label_for(?string $value): string
    {
        return self::tryFrom((string) $value)?->label() ?? (string) $value;
    }
}
