<?php

namespace App\Filament\Resources\Disputes\Schemas;

use App\Actions\Med\GenerateAiDefenseAction;
use App\Actions\Med\MatchDossierAction;
use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Enums\DossierSource;
use App\Enums\SourceStatus;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\MerchantStore;
use App\Services\SellerNotifier;
use App\Support\TaxId;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class DisputeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Grid::make(1)
                    ->columnSpan(2)
                    ->schema([
                        Section::make('O caso')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->formatStateUsing(fn (DisputeStatus $state): string => $state->label())
                                    ->color(fn (DisputeStatus $state): string => $state->color()),
                                TextEntry::make('value')
                                    ->label('Valor')
                                    ->formatStateUsing(fn (Dispute $record): string => $record->formattedValue()),
                                TextEntry::make('respond_by')
                                    ->label('Prazo de resposta')
                                    ->dateTime('d/m/Y H:i', 'America/Sao_Paulo')
                                    ->color(fn (Dispute $record): string => $record->deadlineColor())
                                    ->placeholder('—'),
                                TextEntry::make('type')->label('Tipo'),
                                TextEntry::make('woovi_status')->label('Status na Woovi')->placeholder('—'),
                                TextEntry::make('dispute_reason')->label('Motivo')->placeholder('—'),
                                TextEntry::make('situation_type')->label('Situação')->placeholder('—'),
                                TextEntry::make('opened_at')->label('Aberto em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->placeholder('—'),
                                TextEntry::make('evidence_sent_at')->label('Enviado à Woovi em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->placeholder('—'),
                                TextEntry::make('end_to_end_id')->label('endToEndId')->copyable()->columnSpanFull(),
                            ]),

                        Section::make('Defesa da ÚnicoPag')
                            ->description('Texto que vai na carta enviada à Woovi. A IA redige o rascunho; quem decide é o analista.')
                            ->schema([
                                TextEntry::make('ai_recommendation')
                                    ->label('Recomendação da IA')
                                    ->state(fn (Dispute $record): ?string => $record->ai_defense
                                        ? ucfirst(GenerateAiDefenseAction::recommendationLabel(data_get($record->ai_defense, 'recommendation')))
                                        : null)
                                    ->helperText(fn (Dispute $record): ?string => data_get($record->ai_defense, 'recommendation_reason'))
                                    ->badge()
                                    ->color(fn (Dispute $record): string => match (data_get($record->ai_defense, 'recommendation')) {
                                        'defender' => 'success',
                                        'defender_com_ressalvas' => 'warning',
                                        'nao_defender' => 'danger',
                                        default => 'gray',
                                    })
                                    ->placeholder('Sendo preparada: o rascunho sai depois da montagem do dossiê.'),
                                TextEntry::make('defense_text')
                                    ->label('Texto da defesa (vai na carta)')
                                    ->placeholder('Sem texto. Use "Mais > Texto da defesa".')
                                    ->extraAttributes(['class' => 'whitespace-pre-line']),
                                TextEntry::make('ai_arguments')
                                    ->label('Argumentos')
                                    ->state(fn (Dispute $record): array => (array) data_get($record->ai_defense, 'arguments', []))
                                    ->listWithLineBreaks()->bulleted()
                                    ->visible(fn (Dispute $record): bool => filled(data_get($record->ai_defense, 'arguments'))),
                                TextEntry::make('ai_weaknesses')
                                    ->label('Pontos fracos (o banco pode explorar)')
                                    ->state(fn (Dispute $record): array => (array) data_get($record->ai_defense, 'weaknesses', []))
                                    ->listWithLineBreaks()->bulleted()->color('warning')
                                    ->visible(fn (Dispute $record): bool => filled(data_get($record->ai_defense, 'weaknesses'))),
                                TextEntry::make('ai_missing')
                                    ->label('Provas que faltam')
                                    ->state(fn (Dispute $record): array => (array) data_get($record->ai_defense, 'missing_evidence', []))
                                    ->listWithLineBreaks()->bulleted()
                                    ->visible(fn (Dispute $record): bool => filled(data_get($record->ai_defense, 'missing_evidence'))),
                                TextEntry::make('ai_defense_generated_at')
                                    ->label('Rascunho gerado em')
                                    ->dateTime('d/m/Y H:i', 'America/Sao_Paulo')
                                    ->helperText(fn (Dispute $record): ?string => data_get($record->ai_defense, 'model') ? 'Modelo: '.data_get($record->ai_defense, 'model').(data_get($record->ai_defense, 'used_claim') ? ' · usou o relato de quem contestou' : ' · sem o relato de quem contestou') : null)
                                    ->placeholder('—'),
                            ]),

                        Section::make('Resposta do lojista')
                            ->visible(fn (Dispute $record): bool => $record->seller_submitted_at !== null || filled($record->complement_request) || filled($record->analyst_notes))
                            ->schema([
                                TextEntry::make('complement_request')
                                    ->label('Complemento pedido')
                                    ->visible(fn (Dispute $record): bool => filled($record->complement_request))
                                    ->color('warning'),
                                TextEntry::make('seller_contact_name')->label('Enviada por')->placeholder('Ainda não enviada'),
                                TextEntry::make('seller_submitted_at')->label('Enviada em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->placeholder('—'),
                                TextEntry::make('seller_details')
                                    ->label('Informado pelo lojista')
                                    ->state(fn (Dispute $record): ?string => collect([
                                        'Pedido' => data_get($record->seller_details, 'order_reference'),
                                        'Rastreio' => data_get($record->seller_details, 'tracking_code'),
                                    ])->filter()->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · ') ?: null)
                                    ->visible(fn (Dispute $record): bool => filled($record->seller_details)),
                                TextEntry::make('seller_statement')
                                    ->label('Relato')
                                    ->placeholder('O lojista ainda não mandou o relato.')
                                    ->extraAttributes(['class' => 'whitespace-pre-line']),
                                TextEntry::make('analyst_notes')
                                    ->label('Parecer da ÚnicoPag (vai na carta de defesa)')
                                    ->placeholder('Sem parecer. Use "Mais > Parecer da ÚnicoPag".')
                                    ->extraAttributes(['class' => 'whitespace-pre-line']),
                            ]),

                        Section::make('Conferência do dossiê')
                            ->description(fn (Dispute $record): string => match (true) {
                                $record->dossier_match === null => 'Ainda não conferido.',
                                (bool) data_get($record->dossier_match, 'ready') => 'Pronto para envio: nenhuma divergência que bloqueie.',
                                default => 'Com pendência ou divergência - confira antes de enviar.',
                            })
                            ->visible(fn (Dispute $record): bool => $record->dossier_match !== null)
                            ->schema([
                                RepeatableEntry::make('dossier_checks')
                                    ->hiddenLabel()
                                    ->state(fn (Dispute $record): array => (array) data_get($record->dossier_match, 'checks', []))
                                    ->columns(3)
                                    ->schema([
                                        TextEntry::make('label')->hiddenLabel()->weight('medium'),
                                        TextEntry::make('status')
                                            ->hiddenLabel()
                                            ->badge()
                                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                                MatchDossierAction::OK => 'Ok',
                                                MatchDossierAction::DIVERGENT => 'Divergente',
                                                default => 'Sem dado',
                                            })
                                            ->color(fn (string $state): string => match ($state) {
                                                MatchDossierAction::OK => 'success',
                                                MatchDossierAction::DIVERGENT => 'danger',
                                                default => 'gray',
                                            }),
                                        TextEntry::make('detail')->hiddenLabel()->color('gray'),
                                    ]),
                            ]),

                        Section::make('Dossiê automático')
                            ->description('O que o sistema puxou sozinho da Woovi, da plataforma e do KYC.')
                            ->collapsed()
                            ->visible(fn (Dispute $record): bool => $record->dossier_built_at !== null)
                            ->schema([
                                RepeatableEntry::make('dossier_sources')
                                    ->hiddenLabel()
                                    ->state(fn (Dispute $record) => $record->sources()->orderBy('id')->get())
                                    ->columns(3)
                                    ->schema([
                                        TextEntry::make('source')->hiddenLabel()->formatStateUsing(fn (DossierSource $state): string => $state->label()),
                                        TextEntry::make('status')->hiddenLabel()->badge()
                                            ->formatStateUsing(fn (SourceStatus $state): string => $state->label())
                                            ->color(fn (SourceStatus $state): string => $state->color()),
                                        TextEntry::make('fetched_at')->hiddenLabel()->since()->color('gray'),
                                        TextEntry::make('error')->hiddenLabel()->color('danger')->columnSpanFull()
                                            ->visible(fn ($record): bool => filled($record->error)),
                                    ]),
                            ]),

                        Section::make('Evidências')
                            ->schema([
                                RepeatableEntry::make('evidences')
                                    ->hiddenLabel()
                                    ->placeholder('Nenhum arquivo ainda.')
                                    ->columns(4)
                                    ->schema([
                                        TextEntry::make('kind')
                                            ->label('Tipo')
                                            ->formatStateUsing(fn ($state): string => $state->label())
                                            ->columnSpan(2),
                                        TextEntry::make('uploaded_by')
                                            ->label('Origem')
                                            ->formatStateUsing(fn (string $state, DisputeEvidence $record): string => match (true) {
                                                $state === 'seller' => 'Seller',
                                                $state === 'analyst' => 'Analista',
                                                $record->source === 'defense_letter' => 'Carta (sistema)',
                                                default => 'Dossiê automático',
                                            }),
                                        TextEntry::make('sent_at')
                                            ->label('Na Woovi')
                                            ->dateTime('d/m H:i', 'America/Sao_Paulo')
                                            ->placeholder('Não enviado')
                                            ->color(fn (DisputeEvidence $record): string => $record->last_error ? 'danger' : 'gray'),
                                        TextEntry::make('description')->label('Descrição')->columnSpan(3),
                                        TextEntry::make('original_name')
                                            ->label('Arquivo')
                                            ->url(fn (DisputeEvidence $record): string => route('admin.evidence.download', $record))
                                            ->openUrlInNewTab()
                                            ->color('primary')
                                            ->helperText(fn (DisputeEvidence $record): string => Number::fileSize($record->size)),
                                        TextEntry::make('last_error')
                                            ->label('Último erro')
                                            ->color('danger')
                                            ->visible(fn (DisputeEvidence $record): bool => filled($record->last_error))
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ]),

                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make('Lojista')
                            ->schema([
                                TextEntry::make('seller_name')
                                    ->label('Lojista')
                                    ->state(fn (Dispute $record): string => $record->merchantName() ?? 'Não identificado')
                                    ->color(fn (Dispute $record): ?string => $record->merchant ? null : 'danger')
                                    ->weight('medium')
                                    ->helperText(fn (Dispute $record): string => $record->merchant
                                        ? 'Pela venda na plataforma'.($record->merchant_account_id ? " (conta {$record->merchant_account_id})" : '').' · conta Woovi: '.$record->wooviAccount->name
                                        : 'Venda não localizada na plataforma pelo correlationID da cobrança. Conta Woovi: '.$record->wooviAccount->name),
                                TextEntry::make('store_website')
                                    ->label('Site da loja')
                                    ->state(fn (Dispute $record): ?string => $record->merchantStore()?->website)
                                    ->url(fn (Dispute $record): ?string => $record->merchantStore()?->website)
                                    ->openUrlInNewTab()
                                    ->color('primary')
                                    ->helperText(fn (Dispute $record): ?string => ($store = $record->merchantStore()) === null ? null : match ($store->capture_status) {
                                        'ok', 'partial' => 'Políticas capturadas em '.$store->captured_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i').': '
                                            .collect((array) $store->captures)->map(fn (array $c): string => MerchantStore::KINDS[$c['kind']] ?? $c['kind'])->implode(', ').'.',
                                        'failed' => 'Captura falhou: '.$store->capture_error,
                                        'no_website' => 'Sem site: preencha em Lojas.',
                                        default => 'Captura das políticas pendente.',
                                    })
                                    ->placeholder('Sem site: preencha em Lojas.')
                                    ->visible(fn (Dispute $record): bool => filled($record->merchant_account_id)),
                                TextEntry::make('merchant_kyc')->label('KYC')->state(fn (Dispute $record): ?string => data_get($record->merchant, 'kyc_status'))->badge()->color('gray')->visible(fn (Dispute $record): bool => filled(data_get($record->merchant, 'kyc_status'))),
                                TextEntry::make('seller_tax_id')->label('CPF/CNPJ')->state(fn (Dispute $record): ?string => TaxId::format($record->seller()['tax_id']))->placeholder('—'),
                                TextEntry::make('seller_email')->label('E-mail')->state(fn (Dispute $record): ?string => $record->seller()['email'])->copyable()->placeholder('sem e-mail'),
                                TextEntry::make('seller_phone')->label('Telefone')->state(fn (Dispute $record): ?string => $record->seller()['phone'])->copyable()->placeholder('—'),
                                TextEntry::make('seller_link')
                                    ->label('Link da defesa')
                                    ->state(fn (Dispute $record): string => $record->sellerLink())
                                    ->copyable()
                                    ->copyMessage('Link copiado!')
                                    ->limit(40)
                                    ->helperText(fn (Dispute $record): string => $record->isTokenExpired()
                                        ? 'Link expirado.'
                                        : 'Vale até '.($record->token_expires_at?->timezone('America/Sao_Paulo')->format('d/m H:i') ?? 'o fim do caso').'.'),
                                TextEntry::make('seller_notified_at')->label('Avisado em')->visible(fn (): bool => SellerNotifier::enabled())->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->placeholder('Não avisado'),
                                TextEntry::make('seller_opened_at')->label('Abriu o link em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->placeholder('Não abriu'),
                            ]),

                        Section::make('Pagador (quem contestou)')
                            ->collapsed()
                            ->schema([
                                TextEntry::make('payer_name')->label('Nome')->placeholder('A Woovi não informa no MED'),
                                TextEntry::make('payer_email')->label('E-mail')->placeholder('—'),
                                TextEntry::make('payer_phone')->label('Telefone')->placeholder('—'),
                            ]),

                        Section::make('Linha do tempo')
                            ->schema([
                                RepeatableEntry::make('events')
                                    ->hiddenLabel()
                                    ->state(fn (Dispute $record) => $record->events()->with('user')
                                        // Releitura sem novidade (eventos antigos) não diz nada ao analista.
                                        ->where('event', '!=', DisputeEventType::SYNCED->value)
                                        ->latest('occurred_at')->latest('id')->limit(30)->get())
                                    ->schema([
                                        TextEntry::make('event')
                                            ->hiddenLabel()
                                            ->formatStateUsing(fn (string $state): string => DisputeEventType::label_for($state))
                                            ->badge()
                                            ->color(fn ($record): string => match ($record->level) {
                                                'error' => 'danger',
                                                'warning' => 'warning',
                                                default => 'gray',
                                            }),
                                        TextEntry::make('message')
                                            ->hiddenLabel()
                                            ->helperText(fn ($record): string => collect([
                                                $record->occurred_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                                                $record->source,
                                                $record->user?->name,
                                            ])->filter()->implode(' · ')),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
