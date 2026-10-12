<?php

namespace App\Support;

use App\Models\Dispute;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Uma operação por caso de cada vez: montagem do dossiê (job, botão, comando) e
 * envio à Woovi (analista, automático) usam a mesma trava. Sem ela, uma montagem
 * podia trocar o arquivo e o correlationID de um documento no meio do envio dele,
 * e a linha ficava marcada como enviada com um conteúdo que a Woovi nunca recebeu.
 */
class DisputeLock
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Dispute $dispute, Closure $callback, int $waitSeconds = 60): mixed
    {
        try {
            return Cache::lock(self::key($dispute), 600)->block($waitSeconds, $callback);
        } catch (LockTimeoutException) {
            throw new RuntimeException('O caso está sendo processado (envio ou montagem do dossiê). Tente de novo em instantes.');
        }
    }

    public static function key(Dispute $dispute): string
    {
        return "med:caso:{$dispute->id}";
    }
}
