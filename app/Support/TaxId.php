<?php

namespace App\Support;

/**
 * CPF/CNPJ para exibição. Guardado sempre só com dígitos.
 */
class TaxId
{
    public static function format(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return match (strlen($digits)) {
            11 => preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digits),
            14 => preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digits),
            0 => null,
            default => $digits,
        };
    }
}
