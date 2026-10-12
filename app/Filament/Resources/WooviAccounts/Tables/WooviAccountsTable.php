<?php

namespace App\Filament\Resources\WooviAccounts\Tables;

use App\Actions\Med\RegisterWooviWebhooksAction;
use App\Actions\Med\SyncAccountDisputesAction;
use App\Enums\WooviAccountSource;
use App\Models\WooviAccount;
use App\Support\Permissions;
use App\Support\TaxId;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class WooviAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['disputes', 'disputes as open_disputes_count' => fn ($q) => $q->open()]))
            ->columns([
                TextColumn::make('name')->label('Conta')->description(fn (WooviAccount $record): ?string => TaxId::format($record->tax_id))->searchable(['name', 'tax_id'])->sortable(),
                TextColumn::make('unicopag_account_id')->label('unico-api')->placeholder('—')->searchable()->toggleable(),
                TextColumn::make('source')->label('Origem')->badge()->color('gray')->formatStateUsing(fn (WooviAccountSource $state): string => $state->label()),
                TextColumn::make('contact_email')->label('E-mail')->placeholder('sem e-mail')->toggleable(),
                TextColumn::make('open_disputes_count')->label('MEDs abertos')->badge()->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('disputes_count')->label('Total')->badge()->color('gray'),
                IconColumn::make('webhooks_registered_at')->label('Webhooks')->boolean()->getStateUsing(fn (WooviAccount $record): bool => $record->webhooks_registered_at !== null),
                IconColumn::make('is_active')->label('Ativa')->boolean(),
                TextColumn::make('last_synced_at')->label('Sincronizada')->since()->placeholder('Nunca')->toggleable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    Action::make('registerWebhooks')
                        ->label('Cadastrar webhooks')
                        ->icon('heroicon-o-bolt')
                        ->requiresConfirmation()
                        ->modalDescription('Cadastra na conta Woovi os eventos OPENPIX:DISPUTE_* apontando para este sistema. Eventos já cadastrados são pulados.')
                        ->action(function (WooviAccount $record, RegisterWooviWebhooksAction $register): void {
                            try {
                                $created = $register->handle($record);
                            } catch (Throwable $e) {
                                Notification::make()->danger()->title('Woovi recusou')->body($e->getMessage())->send();

                                return;
                            }

                            Notification::make()->success()
                                ->title($created === [] ? 'Webhooks já estavam cadastrados' : count($created).' webhook(s) cadastrados')
                                ->send();
                        }),
                    Action::make('syncDisputes')
                        ->label('Buscar MEDs na Woovi')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function (WooviAccount $record, SyncAccountDisputesAction $sync): void {
                            try {
                                $result = $sync->handle($record);
                            } catch (Throwable $e) {
                                Notification::make()->danger()->title('Woovi recusou')->body($e->getMessage())->send();

                                return;
                            }

                            Notification::make()->success()
                                ->title("{$result['seen']} disputa(s) na Woovi, {$result['created']} nova(s)")
                                ->send();
                        }),
                ])->visible(fn (): bool => Permissions::allows(Permissions::MANAGE_WOOVI_ACCOUNTS)),
            ]);
    }
}
