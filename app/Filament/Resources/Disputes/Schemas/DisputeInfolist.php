<?php

namespace App\Filament\Resources\Disputes\Schemas;

use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
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

                        Section::make('Defesa do seller')
                            ->schema([
                                TextEntry::make('complement_request')
                                    ->label('Complemento pedido')
                                    ->visible(fn (Dispute $record): bool => filled($record->complement_request))
                                    ->color('warning'),
                                TextEntry::make('seller_contact_name')->label('Enviada por')->placeholder('Ainda não enviada'),
                                TextEntry::make('seller_submitted_at')->label('Enviada em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->placeholder('—'),
                                TextEntry::make('seller_statement')
                                    ->label('Relato')
                                    ->placeholder('O seller ainda não mandou o relato.')
                                    ->extraAttributes(['class' => 'whitespace-pre-line']),
                                TextEntry::make('analyst_notes')
                                    ->label('Parecer da ÚnicoPag (vai na carta de defesa)')
                                    ->placeholder('Sem parecer. Use "Mais > Parecer da ÚnicoPag".')
                                    ->extraAttributes(['class' => 'whitespace-pre-line']),
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
                                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                                'seller' => 'Seller',
                                                'analyst' => 'Analista',
                                                default => 'Sistema',
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
                        Section::make('Seller')
                            ->schema([
                                TextEntry::make('wooviAccount.name')->label('Conta'),
                                TextEntry::make('wooviAccount.tax_id')->label('CPF/CNPJ')->formatStateUsing(fn (?string $state): ?string => TaxId::format($state))->placeholder('—'),
                                TextEntry::make('wooviAccount.contact_email')->label('E-mail')->copyable()->placeholder('sem e-mail'),
                                TextEntry::make('wooviAccount.contact_phone')->label('Telefone')->copyable()->placeholder('—'),
                                TextEntry::make('seller_link')
                                    ->label('Link da defesa')
                                    ->state(fn (Dispute $record): string => $record->sellerLink())
                                    ->copyable()
                                    ->copyMessage('Link copiado!')
                                    ->limit(40)
                                    ->helperText(fn (Dispute $record): string => $record->isTokenExpired()
                                        ? 'Link expirado.'
                                        : 'Vale até '.($record->token_expires_at?->timezone('America/Sao_Paulo')->format('d/m H:i') ?? 'o fim do caso').'.'),
                                TextEntry::make('seller_notified_at')->label('Avisado em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->placeholder('Não avisado'),
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
                                    ->state(fn (Dispute $record) => $record->events()->with('user')->latest('occurred_at')->latest('id')->limit(50)->get())
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
