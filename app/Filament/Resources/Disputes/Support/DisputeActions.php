<?php

namespace App\Filament\Resources\Disputes\Support;

use App\Actions\Med\BuildDossierAction;
use App\Actions\Med\MatchDossierAction;
use App\Actions\Med\RequestComplementAction;
use App\Actions\Med\SendEvidenceToWooviAction;
use App\Actions\Med\StoreEvidenceFile;
use App\Actions\Med\SyncDisputeAction;
use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Enums\EvidenceKind;
use App\Models\Dispute;
use App\Services\DisputeEventRecorder;
use App\Services\SellerNotifier;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Ações da ficha do MED. Cada uma chama uma Action de App\Actions\Med - a regra
 * mora lá, aqui só a tela.
 */
class DisputeActions
{
    public static function sendToWoovi(): Action
    {
        return Action::make('sendToWoovi')
            ->label('Enviar à Woovi')
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Enviar a defesa à Woovi')
            ->modalDescription(fn (ViewRecord $livewire): string => self::sendDescription($livewire->getRecord()))
            ->modalSubmitActionLabel('Enviar agora')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::SEND_DISPUTES)
                && $livewire->getRecord()->status->canSendToWoovi())
            ->action(function (ViewRecord $livewire, SendEvidenceToWooviAction $send): void {
                try {
                    $sent = $send->handle($livewire->getRecord(), auth()->id());
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('Envio não concluído')->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title("{$sent} documento(s) enviados à Woovi")->send();
                $livewire->getRecord()->refresh();
            });
    }

    public static function requestComplement(): Action
    {
        return Action::make('requestComplement')
            ->label('Pedir complemento')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->modalHeading('Pedir complemento ao seller')
            ->modalDescription('O caso volta para "Aguardando seller", o link reabre e o seller recebe esta mensagem por e-mail.')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::REVIEW_DISPUTES)
                && in_array($livewire->getRecord()->status, [DisputeStatus::SELLER_SUBMITTED, DisputeStatus::PENDING_SELLER], true))
            ->schema([
                Textarea::make('message')
                    ->label('O que falta')
                    ->placeholder('Ex.: envie o comprovante de entrega com a assinatura do comprador.')
                    ->required()
                    ->minLength(10)
                    ->maxLength(2000)
                    ->rows(4),
            ])
            ->action(function (array $data, ViewRecord $livewire, RequestComplementAction $request): void {
                $request->handle($livewire->getRecord(), (string) $data['message']);
                Notification::make()->success()->title('Complemento pedido ao seller')->send();
            });
    }

    public static function editAnalystNotes(): Action
    {
        return Action::make('editAnalystNotes')
            ->label('Parecer da ÚnicoPag')
            ->icon('heroicon-o-pencil-square')
            ->modalDescription('Vai na carta de defesa enviada à Woovi, depois do relato do seller.')
            ->visible(fn (): bool => Permissions::allows(Permissions::REVIEW_DISPUTES))
            ->fillForm(fn (ViewRecord $livewire): array => ['analyst_notes' => $livewire->getRecord()->analyst_notes])
            ->schema([
                Textarea::make('analyst_notes')->label('Parecer')->rows(6)->maxLength(5000),
            ])
            ->action(function (array $data, ViewRecord $livewire): void {
                $livewire->getRecord()->forceFill(['analyst_notes' => $data['analyst_notes'] ?: null])->save();
                Notification::make()->success()->title('Parecer salvo')->send();
            });
    }

    public static function attachEvidence(): Action
    {
        return Action::make('attachEvidence')
            ->label('Anexar evidência')
            ->icon('heroicon-o-paper-clip')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::REVIEW_DISPUTES)
                && $livewire->getRecord()->status->canSendToWoovi())
            ->schema([
                Select::make('kind')->label('Tipo')->options(EvidenceKind::sellerOptions())->required(),
                Textarea::make('description')->label('O que o documento mostra')->required()->maxLength(450)->rows(2),
                FileUpload::make('file')
                    ->label('Arquivo')
                    ->required()
                    ->storeFiles(false)
                    ->maxSize((int) config('med.evidencias.max_kb'))
                    ->acceptedFileTypes(['application/pdf', 'image/png', 'image/jpeg', 'image/webp']),
            ])
            ->action(function (array $data, ViewRecord $livewire, StoreEvidenceFile $store, DisputeEventRecorder $events): void {
                // O FileUpload pode entregar o arquivo sozinho ou dentro de um array.
                $file = is_array($data['file']) ? reset($data['file']) : $data['file'];

                if (! $file instanceof UploadedFile && ! $file instanceof TemporaryUploadedFile) {
                    Notification::make()->danger()->title('Arquivo não recebido')->send();

                    return;
                }

                $dispute = $livewire->getRecord();
                $evidence = $store->handle($dispute, $file, EvidenceKind::from($data['kind']), (string) $data['description'], 'analyst');

                if ($evidence === null) {
                    Notification::make()->warning()->title('Arquivo repetido')->body('O caso já tem uma evidência com exatamente este conteúdo.')->send();

                    return;
                }

                $events->record(DisputeEventType::ANALYST_EVIDENCE_ADDED, $dispute, "Analista anexou {$evidence->original_name}.", ['evidence_id' => $evidence->id], source: 'analyst');
                Notification::make()->success()->title('Evidência anexada')->send();
            });
    }

    public static function resendSellerNotice(): Action
    {
        return Action::make('resendSellerNotice')
            ->label('Reenviar aviso ao seller')
            ->icon('heroicon-o-envelope')
            ->requiresConfirmation()
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::REVIEW_DISPUTES)
                && $livewire->getRecord()->status === DisputeStatus::PENDING_SELLER)
            ->action(function (ViewRecord $livewire, SellerNotifier $notifier): void {
                $dispute = $livewire->getRecord();
                $sent = $notifier->disputeOpened($dispute, $dispute->complement_request);

                $sent
                    ? Notification::make()->success()->title('Aviso reenviado')->send()
                    : Notification::make()->warning()->title('Aviso não enviado')->body('Veja o motivo na linha do tempo. O link da ficha pode ser mandado à mão.')->send();
            });
    }

    /**
     * Tira do caso uma evidência ainda não enviada (documento errado da plataforma,
     * arquivo do seller que não serve). O que já foi para a Woovi não sai mais.
     */
    public static function discardEvidence(): Action
    {
        return Action::make('discardEvidence')
            ->label('Descartar evidência')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->modalDescription('A evidência sai do caso e não vai para a Woovi. Só vale para o que ainda não foi enviado.')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::REVIEW_DISPUTES)
                && $livewire->getRecord()->status->canSendToWoovi()
                && $livewire->getRecord()->evidences()->whereNull('sent_at')->where('kind', '!=', EvidenceKind::DEFENSE_LETTER->value)->exists())
            ->schema([
                Select::make('evidence_id')
                    ->label('Evidência')
                    ->required()
                    ->options(fn (ViewRecord $livewire): array => $livewire->getRecord()->evidences()
                        ->whereNull('sent_at')
                        ->where('kind', '!=', EvidenceKind::DEFENSE_LETTER->value)
                        ->get()
                        ->mapWithKeys(fn ($e): array => [$e->id => "{$e->kind->label()} - {$e->original_name}"])
                        ->all()),
                Textarea::make('reason')->label('Por quê')->required()->maxLength(500)->rows(2),
            ])
            ->action(function (array $data, ViewRecord $livewire, DisputeEventRecorder $events): void {
                $dispute = $livewire->getRecord();
                $evidence = $dispute->evidences()->whereNull('sent_at')->whereKey($data['evidence_id'])->first();

                if (! $evidence || $evidence->kind === EvidenceKind::DEFENSE_LETTER) {
                    Notification::make()->warning()->title('Evidência não pode ser descartada')->send();

                    return;
                }

                // Soft delete: some do caso e do envio; o arquivo fica para auditoria.
                $evidence->delete();

                $events->record(DisputeEventType::EVIDENCE_DISCARDED, $dispute, "Descartada: {$evidence->kind->label()} ({$evidence->original_name}). Motivo: {$data['reason']}", ['source' => $evidence->source], source: 'analyst');
                Notification::make()->success()->title('Evidência descartada')->send();
                $livewire->getRecord()->refresh();
            });
    }

    public static function rebuildDossier(): Action
    {
        return Action::make('rebuildDossier')
            ->label('Montar dossiê agora')
            ->icon('heroicon-o-document-magnifying-glass')
            ->modalDescription('Busca de novo a transação, a cobrança, as devoluções e o comprovante na Woovi, a venda e o cadastro no unico-api e o status do KYC, regenera os documentos que mudaram e refaz a conferência. O que já foi enviado à Woovi não muda.')
            ->requiresConfirmation()
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::REVIEW_DISPUTES)
                && ! $livewire->getRecord()->status->isClosed())
            ->action(function (ViewRecord $livewire, BuildDossierAction $build): void {
                $dispute = $build->handle($livewire->getRecord(), refresh: true);

                Notification::make()
                    ->title('Dossiê montado: '.((bool) data_get($dispute->dossier_match, 'ready') ? 'conferência ok' : 'com pendências'))
                    ->{(bool) data_get($dispute->dossier_match, 'ready') ? 'success' : 'warning'}()
                    ->send();

                $livewire->getRecord()->refresh();
            });
    }

    public static function syncWithWoovi(): Action
    {
        return Action::make('syncWithWoovi')
            ->label('Reler na Woovi')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (): bool => Permissions::allows(Permissions::REVIEW_DISPUTES))
            ->action(function (ViewRecord $livewire, SyncDisputeAction $sync): void {
                try {
                    $sync->handle($livewire->getRecord());
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('Woovi não respondeu')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Caso atualizado com a Woovi')->send();
                $livewire->getRecord()->refresh();
            });
    }

    private static function sendDescription(Dispute $dispute): string
    {
        $pending = $dispute->evidences()->whereNull('sent_at')->count();
        $lines = ["{$pending} documento(s) ainda não enviados vão para a Woovi, mais a carta de defesa (relato do seller, fatos conferidos e parecer)."];

        $divergent = collect((array) data_get($dispute->dossier_match, 'checks', []))
            ->where('status', MatchDossierAction::DIVERGENT)
            ->pluck('label');

        if ($divergent->isNotEmpty()) {
            $lines[] = 'ATENÇÃO: a conferência do dossiê achou divergência em: '.$divergent->implode(', ').'.';
        }

        if ($dispute->status === DisputeStatus::PENDING_SELLER) {
            $lines[] = 'ATENÇÃO: o seller ainda não mandou a defesa. Envie só se o prazo estiver acabando.';
        }

        if ($dispute->hoursLeft() !== null && $dispute->hoursLeft() < 0) {
            $lines[] = 'O prazo interno já venceu - a Woovi pode recusar o envio.';
        }

        $lines[] = 'Depois de enviado, o documento não pode ser retirado.';

        return implode("\n\n", $lines);
    }
}
