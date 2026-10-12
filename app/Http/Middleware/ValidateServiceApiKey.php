<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticação server-to-server por API key compartilhada (header X-API-Key).
 *
 * Parametrizado pela chave de config (ex.: 'service-api-key:services.api.key')
 * para que cada consumidor tenha credencial própria, rotacionável em separado.
 * Mesma lógica fail-closed do check inline do KycTokenController: sem segredo
 * configurado, nega - não há fallback hardcoded.
 */
class ValidateServiceApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $configKey): Response
    {
        $apiKey = (string) $request->header('X-API-Key');
        $expectedApiKey = (string) config($configKey);

        // hash_equals evita timing attack na comparação do segredo.
        if ($expectedApiKey === '' || ! hash_equals($expectedApiKey, $apiKey)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
