<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Support\Permissions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Usuário')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nome')->required()->maxLength(255),
                        TextInput::make('email')->label('E-mail')->email()->required()->unique(ignoreRecord: true)->maxLength(255),
                        TextInput::make('password')
                            ->label('Senha')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->minLength(8)
                            ->maxLength(255)
                            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Em branco mantém a senha atual.' : null),
                    ]),

                Section::make('Papel')
                    ->description('Sem papel o usuário não entra no painel.')
                    ->schema([
                        CheckboxList::make('roles')
                            ->hiddenLabel()
                            ->relationship('roles', 'name')
                            ->descriptions([
                                Permissions::ROLE_SUPER_ADMIN => 'Tudo, inclusive usuários.',
                                Permissions::ROLE_ADMIN => 'MEDs e contas Woovi (AppIDs, webhooks), sem usuários.',
                                Permissions::ROLE_ANALYST => 'Analisa e envia MEDs à Woovi; vê as contas.',
                                Permissions::ROLE_VIEWER => 'Só leitura.',
                            ])
                            ->columns(2),
                    ]),
            ]);
    }
}
