<?php

namespace App\Http\Controllers\Api;

use App\Enums\DisputeStatus;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Leitura para o RiskOps do KYC (X-API-Key própria, services.riskops.key): MEDs e
 * chargebacks por conta da plataforma, sem dado do pagador.
 *
 * A conta é a do lojista do caso (`Dispute::platformAccountId()`): na conta mestre
 * a conta Woovi não tem `unicopag_account_id`, quem diz o lojista é a venda
 * (`merchant_account_id`). Caso sem nenhuma das duas fica de fora.
 */
class RiskOpsDisputesController extends Controller
{
    private const MAX_CASES = 100;

    /**
     * GET /api/riskops/disputas?desde=&contas=
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'desde' => ['required', 'date'],
            'contas' => ['nullable', 'string', 'max:5000'],
        ]);

        $accounts = collect(explode(',', (string) ($data['contas'] ?? '')))
            ->map(fn (string $id): string => trim($id))
            ->filter()
            ->values();

        $rows = $this->cases(Carbon::parse($data['desde']))
            ->get()
            ->filter(fn (Dispute $dispute): bool => $dispute->platformAccountId() !== '')
            ->when($accounts->isNotEmpty(), fn (Collection $cases) => $cases->filter(fn (Dispute $dispute): bool => $accounts->contains($dispute->platformAccountId())))
            ->groupBy(fn (Dispute $dispute): string => $dispute->platformAccountId())
            ->map(function (Collection $cases, string $account): array {
                $med = $cases->reject(fn (Dispute $dispute): bool => self::isChargeback($dispute));
                $chargebacks = $cases->filter(fn (Dispute $dispute): bool => self::isChargeback($dispute));
                $lost = $cases->filter(fn (Dispute $dispute): bool => $dispute->status === DisputeStatus::ACCEPTED);

                return [
                    'conta' => ctype_digit($account) ? (int) $account : $account,
                    'nome' => $cases->map(fn (Dispute $dispute): ?string => $dispute->merchantName())->filter()->first()
                        ?? $cases->first()->wooviAccount?->name,
                    'med' => $med->count(),
                    'med_valor' => (int) $med->sum('value'),
                    'perdidos' => $lost->count(),
                    'perdidos_valor' => (int) $lost->sum('value'),
                    'ganhos' => $cases->filter(fn (Dispute $dispute): bool => $dispute->status === DisputeStatus::REJECTED)->count(),
                    'abertos' => $cases->filter(fn (Dispute $dispute): bool => in_array($dispute->status, [DisputeStatus::PENDING_SELLER, DisputeStatus::SELLER_SUBMITTED, DisputeStatus::EVIDENCE_SENT], true))->count(),
                    'cancelados' => $cases->filter(fn (Dispute $dispute): bool => $dispute->status === DisputeStatus::CANCELED)->count(),
                    'chargebacks' => $chargebacks->count(),
                    'chargebacks_valor' => (int) $chargebacks->sum('value'),
                ];
            })
            ->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * GET /api/riskops/disputas/{conta}?desde= - os casos da conta, do mais novo.
     */
    public function show(Request $request, string $account): JsonResponse
    {
        $data = $request->validate(['desde' => ['required', 'date']]);

        $rows = $this->cases(Carbon::parse($data['desde']))
            ->get()
            ->filter(fn (Dispute $dispute): bool => $dispute->platformAccountId() === $account)
            ->sortByDesc(fn (Dispute $dispute) => ($dispute->opened_at ?? $dispute->created_at)->getTimestamp())
            ->take(self::MAX_CASES)
            ->map(fn (Dispute $dispute): array => [
                'id' => $dispute->id,
                'tipo' => $dispute->type,
                'status' => $dispute->status->value,
                'valor' => $dispute->value,
                'motivo' => $dispute->dispute_reason,
                'aberto_em' => ($dispute->opened_at ?? $dispute->created_at)->toIso8601String(),
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * Casos desde a data (abertura na Woovi, ou criação aqui quando não houver), só
     * com o que a resposta usa: nada do pagador sai do banco.
     *
     * @return Builder<Dispute>
     */
    private function cases(Carbon $since): Builder
    {
        return Dispute::query()
            ->select(['id', 'woovi_account_id', 'type', 'status', 'value', 'dispute_reason', 'opened_at', 'created_at', 'merchant', 'merchant_account_id'])
            ->with('wooviAccount:id,name,unicopag_account_id')
            ->where(fn (Builder $query) => $query
                ->where('opened_at', '>=', $since)
                ->orWhere(fn (Builder $query) => $query->whereNull('opened_at')->where('created_at', '>=', $since)));
    }

    private static function isChargeback(Dispute $dispute): bool
    {
        return strtoupper((string) $dispute->type) === 'CHARGEBACK';
    }
}
