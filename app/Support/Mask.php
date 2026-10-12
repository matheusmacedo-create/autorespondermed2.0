<?php

namespace App\Support;

/**
 * Mascara dados pessoais do pagador/comprador nos documentos que saem do sistema
 * (evidências enviadas à Woovi e ao banco do pagador). Minimização da LGPD: o
 * suficiente para provar o vínculo, não o dado inteiro.
 */
class Mask
{
    /**
     * CPF: ***.456.789-** | CNPJ: **.345.678/0001-** (o miolo identifica, as pontas não).
     */
    public static function taxId(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return match (strlen($digits)) {
            11 => '***.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-**',
            14 => '**.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3).'/'.substr($digits, 8, 4).'-**',
            0 => null,
            default => str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4),
        };
    }

    public static function email(?string $value): ?string
    {
        $value = trim((string) $value);

        if (! str_contains($value, '@')) {
            return $value === '' ? null : '***';
        }

        [$user, $domain] = explode('@', $value, 2);

        return mb_substr($user, 0, 2).'***@'.$domain;
    }

    public static function phone(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return strlen($digits) < 4 ? null : '****'.substr($digits, -4);
    }

    /**
     * IPv4: último octeto escondido. IPv6: só o prefixo.
     */
    public static function ip(?string $value): ?string
    {
        $value = trim((string) $value);

        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.***', $value);
        }

        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', $value), 0, 3)).':****';
        }

        return null;
    }

    public static function sameTaxId(?string $a, ?string $b): ?bool
    {
        $a = preg_replace('/\D/', '', (string) $a);
        $b = preg_replace('/\D/', '', (string) $b);

        return $a === '' || $b === '' ? null : $a === $b;
    }
}
