<?php

namespace App\Support;

/**
 * Catálogo de permissões e papéis do painel (spatie/laravel-permission). Os
 * nomes moram aqui para o código não espalhar string solta - o seeder, os
 * resources e as ações leem daqui.
 */
final class Permissions
{
    public const VIEW_DISPUTES = 'view disputes';

    // Pedir complemento, escrever parecer, anexar evidência, reler na Woovi.
    public const REVIEW_DISPUTES = 'review disputes';

    // A decisão: mandar a defesa à Woovi.
    public const SEND_DISPUTES = 'send disputes';

    public const VIEW_WOOVI_ACCOUNTS = 'view woovi accounts';

    // Criar/editar contas (inclui o AppID), cadastrar webhooks, sincronizar.
    public const MANAGE_WOOVI_ACCOUNTS = 'manage woovi accounts';

    public const MANAGE_USERS = 'manage users';

    public const ROLE_SUPER_ADMIN = 'Super Admin';

    public const ROLE_ADMIN = 'Admin';

    public const ROLE_ANALYST = 'Analista';

    public const ROLE_VIEWER = 'Visualizador';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::VIEW_DISPUTES,
            self::REVIEW_DISPUTES,
            self::SEND_DISPUTES,
            self::VIEW_WOOVI_ACCOUNTS,
            self::MANAGE_WOOVI_ACCOUNTS,
            self::MANAGE_USERS,
        ];
    }

    /**
     * Permissões de cada papel. O Super Admin passa por cima de tudo pelo
     * Gate::before (AppServiceProvider), mas recebe todas também - assim a
     * tela de usuários mostra o que ele pode de verdade.
     *
     * @return array<string, array<int, string>>
     */
    public static function roles(): array
    {
        return [
            self::ROLE_SUPER_ADMIN => self::all(),
            self::ROLE_ADMIN => [
                self::VIEW_DISPUTES,
                self::REVIEW_DISPUTES,
                self::SEND_DISPUTES,
                self::VIEW_WOOVI_ACCOUNTS,
                self::MANAGE_WOOVI_ACCOUNTS,
            ],
            self::ROLE_ANALYST => [
                self::VIEW_DISPUTES,
                self::REVIEW_DISPUTES,
                self::SEND_DISPUTES,
                self::VIEW_WOOVI_ACCOUNTS,
            ],
            self::ROLE_VIEWER => [
                self::VIEW_DISPUTES,
                self::VIEW_WOOVI_ACCOUNTS,
            ],
        ];
    }

    public static function allows(string $permission): bool
    {
        return auth()->user()?->can($permission) ?? false;
    }
}
