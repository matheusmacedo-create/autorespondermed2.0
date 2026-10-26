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

    /**
     * Nome: primeiro nome inteiro e as iniciais dos demais ("Maria S. O.").
     */
    public static function name(?string $value): ?string
    {
        $parts = preg_split('/\s+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === [] || $parts === false) {
            return null;
        }

        $first = mb_convert_case(array_shift($parts), MB_CASE_TITLE);

        return trim($first.' '.implode(' ', array_map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)).'.', $parts)));
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

    /**
     * Tira caminho e query das URLs de um texto (mensagem de erro de HTTP): fica só
     * o host. URL pré-assinada vale como senha, e a do KYC leva o account_id.
     */
    public static function urls(string $text): string
    {
        return (string) preg_replace_callback(
            '~\bhttps?://([^/\s?#"\']+)[^\s"\']*~i',
            fn (array $m): string => "https://{$m[1]}/…",
            $text,
        );
    }
}
