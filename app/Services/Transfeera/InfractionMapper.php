<?php

namespace App\Services\Transfeera;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Traduz os objetos da Transfeera para o formato que o resto do fluxo já conhece (o
 * da Woovi): a infração vira o payload da ingestão, e o Pix recebido e as devoluções
 * viram as fontes do dossiê. Assim a conferência, a carta e a IA são as mesmas.
 */
class InfractionMapper
{
    /**
     * Infração -> payload do IngestDisputeAction.
     *
     * - `status` é a decisão final da Transfeera: `agreed` = infração procedente (o
     *   lojista perdeu), `disagreed` = improcedente (ganhou). O DisputeStatus traduz.
     * - `amount` vem em centavos, como na Woovi.
     * - O CPF/CNPJ do pagador fica fora: `disputes.raw` não é criptografado. Ele
     *   entra no dossiê pela fonte da alegação, que é.
     *
     * @param  array<string, mixed>  $infraction
     * @return array<string, mixed>
     */
    public static function toDisputePayload(array $infraction): array
    {
        $description = self::string($infraction['infraction_description'] ?? null);

        return array_filter([
            'id' => self::string($infraction['id'] ?? null),
            'endToEndId' => self::string($infraction['transaction_id'] ?? null),
            'type' => 'MED',
            'status' => strtoupper((string) ($infraction['status'] ?? 'pending')),
            'value' => is_numeric($infraction['amount'] ?? null) ? (int) $infraction['amount'] : null,
            'disputeReason' => $description !== null ? mb_substr($description, 0, 250) : null,
            'situationType' => ($situation = self::string($infraction['situation_type'] ?? null)) !== null ? strtoupper($situation) : null,
            'name' => self::string($infraction['payer_name'] ?? null),
            'createdAt' => self::string($infraction['infraction_date'] ?? null),
            'respondBy' => self::string($infraction['analysis_due_date'] ?? null),
            'analysisStatus' => self::string($infraction['analysis_status'] ?? null),
            'analysisDate' => self::string($infraction['analysis_date'] ?? null),
            'isFirstLayer' => is_bool($infraction['is_first_layer'] ?? null) ? $infraction['is_first_layer'] : null,
            'txid' => self::string($infraction['txid'] ?? null),
            'refund' => is_array($infraction['refund'] ?? null) ? Arr::only($infraction['refund'], ['status', 'analysis_status', 'refunded_amount', 'refund_date', 'rejection_reason']) : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Infração -> fonte "alegação do MED" (payload criptografado): o tipo de fraude
     * alegado, o relato de quem contestou e o documento do pagador.
     *
     * @param  array<string, mixed>  $infraction
     * @return array<string, mixed>
     */
    public static function toClaimSource(array $infraction): array
    {
        return array_filter([
            'via' => 'transfeera',
            'endToEndId' => self::string($infraction['transaction_id'] ?? null),
            'situationType' => ($situation = self::string($infraction['situation_type'] ?? null)) !== null ? strtoupper($situation) : null,
            'disputeReason' => self::string($infraction['infraction_description'] ?? null),
            'reportDetails' => self::string($infraction['infraction_description'] ?? null),
            'analysisStatus' => self::string($infraction['analysis_status'] ?? null),
            'isFirstLayer' => is_bool($infraction['is_first_layer'] ?? null) ? $infraction['is_first_layer'] : null,
            'payerTaxId' => self::taxId($infraction['payer_tax_id'] ?? null),
        ], fn ($value) => $value !== null);
    }

    /**
     * Pix recebido (`GET /pix/cashin/{e2e}`) -> fonte "transação", nos campos que a
     * conferência e o registro da venda leem.
     *
     * - `value` vem em reais (50.54) e vira centavos.
     * - O cash-in não tem data: a hora do Pix sai do próprio endToEndId.
     * - O documento do pagador pode vir mascarado ("***.274.581-**"); nesse caso vale
     *   o da infração, que vem inteiro.
     *
     * @param  array<string, mixed>  $cashIn
     * @return array<string, mixed>
     */
    public static function toTransactionSource(array $cashIn, string $endToEndId, ?string $payerTaxIdFromInfraction = null): array
    {
        $taxId = self::taxId($payerTaxIdFromInfraction) ?? self::taxId(data_get($cashIn, 'payer.document'));

        return array_filter([
            'provider' => 'transfeera',
            'endToEndId' => self::string($cashIn['end2end_id'] ?? null) ?? $endToEndId,
            'value' => is_numeric($cashIn['value'] ?? null) ? (int) round(abs((float) $cashIn['value']) * 100) : null,
            'time' => self::paidAt($endToEndId)?->toIso8601String(),
            'transactionID' => self::string($cashIn['txid'] ?? null),
            'integrationId' => self::string($cashIn['integration_id'] ?? null),
            'infoPagador' => self::string($cashIn['pix_description'] ?? null),
            'pixKey' => self::string($cashIn['pix_key'] ?? null),
            'receiptUrl' => self::string($cashIn['receipt_file_url'] ?? null),
            'payer' => array_filter([
                'name' => self::string(data_get($cashIn, 'payer.name')),
                'taxID' => $taxId !== null ? ['taxID' => $taxId] : null,
                'bank' => self::string(data_get($cashIn, 'payer.bank.name')),
            ]),
            'receiver' => array_filter([
                'name' => self::string(data_get($cashIn, 'receiver.name')),
            ]),
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * Devoluções do Pix -> fonte "devoluções", com o status no vocabulário que a
     * conferência conhece. Devolução com erro (`error_code`) não foi feita.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array{refunds: array<int, array<string, mixed>>}
     */
    public static function toRefundsSource(array $entries): array
    {
        return ['refunds' => collect($entries)
            ->filter(fn (array $entry): bool => blank($entry['error_code'] ?? null))
            ->map(fn (array $entry): array => array_filter([
                'value' => is_numeric($entry['value'] ?? null) ? (int) round(abs((float) $entry['value']) * 100) : null,
                'status' => match (strtoupper((string) ($entry['type'] ?? ''))) {
                    'PENDING_DEPOSIT_REFUND' => 'IN_PROCESSING',
                    'CANCELLED_DEPOSIT_REFUND' => 'CANCELED',
                    default => 'CONFIRMED',
                },
                'returnId' => self::string($entry['return_id'] ?? null),
            ], fn ($value) => $value !== null))
            ->values()
            ->all()];
    }

    /**
     * Hora do Pix pelo endToEndId: "E" + ISPB (8) + AAAAMMDDHHmm em UTC + sequência
     * (padrão do Banco Central).
     */
    public static function paidAt(string $endToEndId): ?Carbon
    {
        if (! preg_match('/^E\d{8}(\d{12})/', $endToEndId, $m)) {
            return null;
        }

        return rescue(fn () => Carbon::createFromFormat('YmdHi', $m[1], 'UTC')->startOfMinute(), null, report: false) ?: null;
    }

    /** CPF/CNPJ só quando vem inteiro (11 ou 14 dígitos, sem máscara). */
    private static function taxId(mixed $value): ?string
    {
        if (! is_scalar($value) || str_contains((string) $value, '*')) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', (string) $value);

        return in_array(strlen($digits), [11, 14], true) ? $digits : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_scalar($value) && ! is_bool($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
