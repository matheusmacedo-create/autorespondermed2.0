<?php

namespace App\Http\Controllers\Api;

use App\Enums\WooviAccountSource;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\WooviAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ponte com o unico-api (X-API-Key): o unico-api cadastra a conta Woovi do
 * seller - ele guarda o AppID da subconta em acquirer_recipient_data - e lê os
 * MEDs da conta para mostrar ao seller no dashboard.
 */
class WooviAccountApiController extends Controller
{
    /**
     * PUT /api/woovi-accounts - cria ou atualiza pela accounts.id do unico-api.
     */
    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'app_id' => ['required', 'string', 'max:2000'],
            'tax_id' => ['nullable', 'string', 'max:20'],
            'woovi_company_id' => ['nullable', 'string', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $account = WooviAccount::query()->firstOrNew(['unicopag_account_id' => $data['account_id']]);

        $account->fill([
            'name' => $data['name'],
            'app_id' => $data['app_id'],
            'tax_id' => isset($data['tax_id']) ? preg_replace('/\D/', '', $data['tax_id']) : $account->tax_id,
            'woovi_company_id' => $data['woovi_company_id'] ?? $account->woovi_company_id,
            'contact_name' => $data['contact_name'] ?? $account->contact_name,
            'contact_email' => $data['contact_email'] ?? $account->contact_email,
            'contact_phone' => $data['contact_phone'] ?? $account->contact_phone,
            'is_active' => $data['is_active'] ?? true,
            'source' => $account->exists ? $account->source : WooviAccountSource::UNICO_API,
        ])->save();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $account->id,
                'account_id' => $account->unicopag_account_id,
                'webhook_url' => $account->webhookUrl(),
                'created' => $account->wasRecentlyCreated,
            ],
        ], $account->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * GET /api/woovi-accounts/{accountId}/disputes - MEDs da conta, mais novo primeiro.
     */
    public function disputes(string $accountId): JsonResponse
    {
        $account = WooviAccount::query()->where('unicopag_account_id', $accountId)->first();

        if (! $account) {
            return response()->json(['success' => false, 'message' => 'Conta não encontrada.'], 404);
        }

        $disputes = $account->disputes()
            ->latest('opened_at')
            ->limit(100)
            ->get()
            ->map(fn (Dispute $dispute): array => [
                'id' => $dispute->id,
                'type' => $dispute->type,
                'end_to_end_id' => $dispute->end_to_end_id,
                'value' => $dispute->value,
                'reason' => $dispute->dispute_reason,
                'status' => $dispute->status->value,
                'status_label' => $dispute->status->label(),
                'woovi_status' => $dispute->woovi_status,
                'opened_at' => $dispute->opened_at?->toIso8601String(),
                'respond_by' => $dispute->respond_by?->toIso8601String(),
                'seller_link' => $dispute->status->acceptsSellerSubmission() && ! $dispute->isTokenExpired()
                    ? $dispute->sellerLink()
                    : null,
            ]);

        return response()->json(['success' => true, 'data' => $disputes]);
    }
}
