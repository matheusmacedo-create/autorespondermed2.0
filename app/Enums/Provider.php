<?php

namespace App\Enums;

/**
 * Instituição onde a conta recebe o Pix e onde o MED é respondido
 * (woovi_accounts.provider).
 */
enum Provider: string
{
    case WOOVI = 'woovi';
    case TRANSFEERA = 'transfeera';

    public function label(): string
    {
        return match ($this) {
            self::WOOVI => 'Woovi',
            self::TRANSFEERA => 'Transfeera',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $provider): array => [$provider->value => $provider->label()])
            ->all();
    }
}
