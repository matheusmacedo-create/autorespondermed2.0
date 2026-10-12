<?php

namespace App\Services\Kyc;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Status do onboarding do seller no KYC (unicopag-kyc), pela rota pública
 * GET /api/onboarding/status?account_id=. Só o status: documento do KYC (e a
 * selfie, que é dado biométrico) não vai para evidência de MED.
 */
class KycClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.kyc.base_url'));
    }

    /**
     * approved | rejected | pending | ... ou null quando não há ficha.
     *
     * @throws KycException quando o KYC não respondeu direito
     */
    public function onboardingStatus(string $accountId): ?string
    {
        try {
            $response = Http::acceptJson()
                ->timeout(max(2, (int) config('services.kyc.timeout', 5)))
                ->get(rtrim((string) config('services.kyc.base_url'), '/').'/api/onboarding/status', ['account_id' => $accountId]);
        } catch (Throwable $e) {
            throw new KycException('KYC não respondeu: '.$e->getMessage());
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new KycException("KYC recusou (HTTP {$response->status()}).");
        }

        $status = $response->json('status');

        return is_string($status) && $status !== '' ? $status : null;
    }
}
