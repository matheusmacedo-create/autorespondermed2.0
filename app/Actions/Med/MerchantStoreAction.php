<?php

namespace App\Actions\Med;

use App\Enums\DisputeEventType;
use App\Enums\DossierSource;
use App\Enums\EvidenceKind;
use App\Jobs\CaptureMerchantStore;
use App\Jobs\GenerateAiDefense;
use App\Models\Dispute;
use App\Models\MerchantStore;
use App\Services\DisputeEventRecorder;
use App\Services\Kyc\KycClient;
use App\Services\Stores\StoreCaptureException;
use App\Services\Stores\StoreCapturer;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A loja do lojista do caso: acha o site (cadastro no unico-api, KYC, venda ou o que
 * o analista preencheu), captura as políticas uma vez e anexa os PDFs aos MEDs da
 * mesma loja. Os próximos casos reaproveitam a captura até ela ficar velha.
 */
class MerchantStoreAction
{
    private const RETRY_HOURS = 6;

    public function __construct(
        private readonly StoreCapturer $capturer,
        private readonly KycClient $kyc,
        private readonly StoreSystemEvidence $storeSystemEvidence,
        private readonly DisputeEventRecorder $events,
    ) {}

    /**
     * Chamado no fim da montagem do dossiê: garante a loja, anexa a captura que já
     * existe ou pede uma nova (na fila, o navegador leva até alguns minutos).
     */
    public function forDispute(Dispute $dispute): ?MerchantStore
    {
        if (blank($dispute->merchant_account_id)) {
            return null;
        }

        $store = MerchantStore::query()->firstOrCreate(
            ['merchant_account_id' => $dispute->merchant_account_id],
            ['name' => $dispute->merchantName()],
        );

        if (blank($store->website)) {
            $this->resolveWebsite($store, $dispute);
        }

        // Site que a captura já percorreu sem achar política só volta a ser olhado no
        // prazo de recaptura; erro técnico tenta de novo em algumas horas.
        $retryAfter = $store->capture_status === 'failed' && $store->captured_at !== null
            ? now()->subDays((int) config('med.lojas.recapturar_dias'))
            : now()->subHours(self::RETRY_HOURS);

        if ($store->isFresh()) {
            $this->attach($dispute, $store);
        } elseif (filled($store->website) && $this->capturer->isAvailable()
            && ($store->capture_attempted_at === null || $store->capture_attempted_at->lte($retryAfter))) {
            rescue(function () use ($store): void {
                CaptureMerchantStore::dispatch($store->id);
            }, report: true);
        }

        return $store;
    }

    /**
     * Site da loja, na ordem: cadastro na plataforma, KYC, venda.
     */
    public function resolveWebsite(MerchantStore $store, ?Dispute $dispute = null): void
    {
        $register = $dispute?->sourcePayload(DossierSource::UNICOPAG_REGISTER);
        $sale = $dispute?->sourcePayload(DossierSource::UNICOPAG_SALE);

        $candidates = [
            'unicopag_register' => fn () => data_get($register, 'register.website') ?? data_get($register, 'register.site') ?? data_get($register, 'website'),
            'kyc' => fn () => $this->kyc->storeConfigured() ? rescue(fn () => $this->kyc->storeWebsite($store->merchant_account_id), null, report: false) : null,
            'sale' => fn () => data_get($sale, 'store.url') ?? data_get($sale, 'seller.website'),
        ];

        foreach ($candidates as $source => $resolve) {
            if (($website = self::normalizeUrl($resolve())) !== null) {
                $store->forceFill(['website' => $website, 'website_source' => $source, 'capture_status' => null, 'capture_error' => null])->save();

                return;
            }
        }

        $store->forceFill(['capture_status' => 'no_website'])->save();
    }

    public function capture(MerchantStore $store): MerchantStore
    {
        $store->forceFill(['capture_attempted_at' => now()])->save();

        if (blank($store->website)) {
            $store->forceFill(['capture_status' => 'no_website', 'capture_error' => 'Loja sem site cadastrado.'])->save();

            return $store;
        }

        try {
            $result = $this->capturer->capture($store->website, $store->only(['shipping_url', 'terms_url', 'exchange_url']));
        } catch (StoreCaptureException $e) {
            $store->forceFill(['capture_status' => 'failed', 'capture_error' => $e->getMessage()])->save();

            return $store;
        }

        if ($result['pages'] === []) {
            // A captura rodou e o site não tem política: o que havia de antes sai, senão
            // os casos continuariam com uma política que a loja não publica mais.
            $this->forgetCaptures($store);
            $store->forceFill([
                'captures' => [],
                'captured_at' => now(),
                'capture_status' => 'failed',
                'capture_error' => implode(' · ', $result['errors']) ?: 'Nenhuma página de política encontrada.',
            ])->save();

            return $store;
        }

        $disk = (string) config('med.evidencias.disk');
        $captures = [];

        foreach ($result['pages'] as $page) {
            $sha = hash('sha256', $page['pdf']);
            $path = "med/stores/{$store->id}/{$page['kind']}-".substr($sha, 0, 16).'.pdf';
            Storage::disk($disk)->put($path, $page['pdf']);

            $captures[] = [
                'kind' => $page['kind'],
                'url' => $page['url'],
                'title' => $page['title'],
                'disk' => $disk,
                'path' => $path,
                'size' => strlen($page['pdf']),
                'sha256' => $sha,
                'text' => mb_substr($page['text'], 0, 20000),
                'captured_at' => now()->toIso8601String(),
            ];
        }

        $old = collect((array) $store->captures)->pluck('path')->diff(collect($captures)->pluck('path'));

        $store->forceFill([
            'captures' => $captures,
            'captured_at' => now(),
            'capture_status' => count($captures) === count(MerchantStore::KINDS) ? 'ok' : 'partial',
            'capture_error' => $result['errors'] === [] ? null : implode(' · ', $result['errors']),
        ])->save();

        // Captura antiga sai do disco; a cópia que já virou evidência de um caso fica.
        $old->each(fn (string $path) => rescue(fn () => Storage::disk($disk)->delete($path), report: false));

        return $store;
    }

    private function forgetCaptures(MerchantStore $store): void
    {
        foreach ((array) $store->captures as $capture) {
            rescue(fn () => Storage::disk($capture['disk'])->delete($capture['path']), report: false);
        }
    }

    /**
     * Deixa as políticas do caso iguais às capturadas da loja: anexa a página nova ou
     * mudada e tira a que a loja não tem mais (só o que ainda não foi à Woovi).
     * Devolve quantas mudaram.
     */
    public function attach(Dispute $dispute, MerchantStore $store): int
    {
        if (! $dispute->status->canSendToWoovi()) {
            return 0;
        }

        $captures = $store->isFresh() ? (array) $store->captures : [];
        $current = collect($captures)->map(fn (array $capture): string => "store_{$capture['kind']}")->all();
        $changed = 0;

        if ($store->captured_at !== null) {
            $dispute->evidences()
                ->where('kind', EvidenceKind::STORE_POLICY->value)
                ->whereNull('sent_at')
                ->whereNotIn('source', $current)
                ->get()
                ->each(function ($evidence) use (&$changed): void {
                    rescue(fn () => Storage::disk($evidence->disk)->delete($evidence->path), report: false);
                    $evidence->forceDelete();
                    $changed++;
                });
        }

        foreach ($captures as $capture) {
            $contents = rescue(fn () => Storage::disk($capture['disk'])->get($capture['path']), null, report: false);

            if (! is_string($contents) || $contents === '') {
                continue;
            }

            $before = $dispute->evidences()->where('source', "store_{$capture['kind']}")->latest('id')->value('content_hash');
            $label = MerchantStore::KINDS[$capture['kind']] ?? 'Política';
            $when = $store->captured_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i');

            $this->storeSystemEvidence->handle(
                $dispute,
                "store_{$capture['kind']}",
                EvidenceKind::STORE_POLICY,
                $contents,
                "politica-{$capture['kind']}.pdf",
                'application/pdf',
                "{$label} publicada no site da loja ({$capture['url']}), capturada pela ÚnicoPag em {$when}.",
                $capture['sha256'],
            );

            $changed += $before !== $capture['sha256'] ? 1 : 0;
        }

        if ($changed > 0) {
            $this->events->record(
                DisputeEventType::STORE_POLICY_ATTACHED,
                $dispute,
                $captures === []
                    ? "Políticas do site da loja retiradas: o site não tem página de política ({$store->website})."
                    : "Políticas do site da loja anexadas ({$store->website}).",
                ['store_id' => $store->id],
            );
        }

        return $changed;
    }

    /**
     * Depois de uma captura: anexa aos MEDs abertos da loja e, onde o rascunho da IA
     * ninguém editou, pede um novo com a política real da loja.
     */
    public function spread(MerchantStore $store): void
    {
        $store->disputes()->inWindow()->get()->each(function (Dispute $dispute) use ($store): void {
            try {
                if ($this->attach($dispute, $store) > 0 && config('med.ia_automatica')
                    && (blank($dispute->defense_text) || $dispute->defense_text === data_get($dispute->ai_defense, 'narrative'))) {
                    GenerateAiDefense::dispatch($dispute->id);
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    public static function normalizeUrl(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $value)) {
            $value = 'https://'.$value;
        }

        $parts = parse_url($value);

        return isset($parts['host']) && str_contains($parts['host'], '.') ? $value : null;
    }
}
