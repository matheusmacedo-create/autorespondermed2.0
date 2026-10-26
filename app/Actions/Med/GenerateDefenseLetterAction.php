<?php

namespace App\Actions\Med;

use App\Enums\DisputeEventType;
use App\Enums\DossierSource;
use App\Enums\EvidenceKind;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Services\DisputeEventRecorder;
use App\Support\Mask;
use App\Support\TaxId;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Carta de defesa em PDF: o relato do seller, o parecer do analista, os fatos que a
 * conferência do dossiê confirmou e a lista das evidências, num documento só. A
 * API de evidência da Woovi não tem campo de texto livre (só `description` por
 * documento, 500 caracteres), então o argumento da defesa vai como documento.
 *
 * Só entra na carta o que foi confirmado: divergência da conferência fica no
 * painel. Quem mantém a versão certa (e troca o correlationID quando ela muda) é o
 * StoreSystemEvidence.
 */
class GenerateDefenseLetterAction
{
    public function __construct(
        private readonly StoreSystemEvidence $storeSystemEvidence,
        private readonly DisputeEventRecorder $events,
    ) {}

    public function handle(Dispute $dispute): ?DisputeEvidence
    {
        $dispute->loadMissing(['wooviAccount', 'evidences', 'sources']);

        $data = $this->data($dispute);

        if (blank($data['statement']) && blank($data['analystNotes']) && blank($data['defense']) && $data['facts'] === []) {
            return null;
        }

        $pdf = Pdf::loadView('pdf.defense-letter', $data + [
            'dispute' => $dispute,
            'account' => $dispute->wooviAccount,
            'generatedAt' => now()->timezone('America/Sao_Paulo'),
        ])->setPaper('a4')->output();

        $letter = $this->storeSystemEvidence->handle(
            $dispute,
            'defense_letter',
            EvidenceKind::DEFENSE_LETTER,
            $pdf,
            "carta-de-defesa-{$dispute->end_to_end_id}.pdf",
            'application/pdf',
            'Relato do recebedor, fatos conferidos e lista das evidências anexadas',
            fingerprint: hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE) ?: ''),
        );

        if ($letter->wasRecentlyCreated || $letter->wasChanged('content_hash')) {
            $this->events->record(DisputeEventType::DEFENSE_LETTER_GENERATED, $dispute, 'Carta de defesa gerada.', ['evidence_id' => $letter->id]);
        }

        return $letter;
    }

    /**
     * O conteúdo da carta - também a "impressão digital" que diz se ela mudou.
     *
     * @return array<string, mixed>
     */
    private function data(Dispute $dispute): array
    {
        $register = $dispute->sourcePayload(DossierSource::UNICOPAG_REGISTER);

        return [
            // Cabeçalho no formato da Compliance. Requerente com o nome mascarado
            // (só o primeiro nome inteiro), como todo PDF que sai do sistema.
            'requester' => Mask::name($dispute->payer_name),
            'paidAt' => $dispute->paidAt()?->timezone('America/Sao_Paulo')->format('d/m/Y · H:i'),
            'products' => $dispute->products(),
            'channels' => array_filter([
                'WhatsApp' => config('med.defesa.whatsapp'),
                'E-mail' => config('med.defesa.email'),
            ]),
            'signature' => (string) config('med.defesa.assinatura'),
            'defense' => $dispute->defense_text,
            'statement' => $dispute->seller_statement,
            'analystNotes' => $dispute->analyst_notes,
            'sellerContactName' => $dispute->seller_contact_name,
            'sellerSubmittedAt' => $dispute->seller_submitted_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
            'accountName' => $dispute->seller()['name'],
            'accountTaxId' => TaxId::format($dispute->seller()['tax_id']),
            'tradeName' => data_get($dispute->merchant, 'trade_name') ?: data_get($register, 'register.trade_name'),
            'sellerDetails' => array_filter([
                'Pedido informado pelo recebedor' => data_get($dispute->seller_details, 'order_reference'),
                'Rastreio informado pelo recebedor' => data_get($dispute->seller_details, 'tracking_code'),
            ]),
            'facts' => MatchDossierAction::facts($dispute->dossier_match),
            'evidences' => $dispute->evidences
                ->reject(fn (DisputeEvidence $evidence): bool => $evidence->kind === EvidenceKind::DEFENSE_LETTER)
                ->sortBy('id')
                ->map(fn (DisputeEvidence $evidence): array => [
                    'kind' => $evidence->kind->label(),
                    'description' => $evidence->description,
                    'origin' => $evidence->uploaded_by === 'system' ? 'Reunido pela ÚnicoPag' : ($evidence->uploaded_by === 'analyst' ? 'Anexado pela ÚnicoPag' : 'Enviado pelo recebedor'),
                ])
                ->values()
                ->all(),
        ];
    }
}
