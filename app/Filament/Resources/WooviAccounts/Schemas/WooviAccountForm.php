<?php

namespace App\Filament\Resources\WooviAccounts\Schemas;

use App\Models\WooviAccount;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WooviAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Conta')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nome / razão social')->required()->maxLength(255),
                        TextInput::make('tax_id')
                            ->label('CPF/CNPJ')
                            ->maxLength(20)
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state ? preg_replace('/\D/', '', $state) : null),
                        TextInput::make('unicopag_account_id')
                            ->label('ID da conta no unico-api')
                            ->helperText('accounts.id - liga o MED à conta do seller na plataforma.')
                            ->unique(ignoreRecord: true)
                            ->maxLength(100),
                        TextInput::make('woovi_company_id')->label('ID da empresa na Woovi')->maxLength(100),
                        TextInput::make('app_id')
                            ->label('AppID da Woovi')
                            ->password()
                            ->revealable()
                            ->required(fn (?WooviAccount $record): bool => $record === null)
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                            ->helperText(fn (?WooviAccount $record): string => $record
                                ? 'Em branco mantém o AppID atual. Fica criptografado.'
                                : 'Chave da API da conta (Woovi > API/Plugins). Precisa dos escopos DISPUTE_GET, DISPUTE_GET_LIST, DISPUTE_POST, FILE_POST e WEBHOOK.')
                            ->columnSpanFull(),
                        Toggle::make('is_active')->label('Ativa')->default(true),
                    ]),

                Section::make('Contato do seller')
                    ->description('Para onde vai o aviso de MED com o link da defesa.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('contact_name')->label('Nome')->maxLength(255),
                        TextInput::make('contact_email')->label('E-mail')->email()->maxLength(255),
                        TextInput::make('contact_phone')->label('Telefone')->tel()->maxLength(30),
                    ]),

                Section::make('Webhook')
                    ->visible(fn (?WooviAccount $record): bool => $record !== null)
                    ->schema([
                        TextEntry::make('webhook_url')
                            ->label('URL desta conta')
                            ->state(fn (?WooviAccount $record): ?string => $record?->webhookUrl())
                            ->copyable()
                            ->helperText('Cadastre em Woovi > Webhooks para os eventos OPENPIX:DISPUTE_*, ou use a ação "Cadastrar webhooks" na lista.'),
                        TextEntry::make('webhooks_registered_at')->label('Webhooks cadastrados em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->placeholder('Nunca'),
                        TextEntry::make('last_synced_at')->label('Última sincronização')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->placeholder('Nunca'),
                    ]),
            ]);
    }
}
