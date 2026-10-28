<?php

namespace App\Console\Commands;

use App\Actions\Med\GenerateAiDefenseAction;
use App\Actions\Med\MatchDossierAction;
use App\Actions\Med\SendEvidenceToWooviAction;
use App\Actions\Med\SyncDisputeAction;
use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Jobs\GenerateAiDefense;
use App\Models\Dispute;
use App\Models\User;
use App\Services\Defense\DefenseWriter;
use App\Services\DiscordNotificationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Responde em lote os MEDs abertos que já têm tudo e relê cada um na Woovi antes de
 * enviar. Sem --confirmar só lista o que faria e por que cada caso fica.
 *
 * Sempre fica de fora o que tornaria a carta errada ou inútil: dado incoerente
 * (transação não localizada, valor contestado ou da venda diferente do Pix,
 * devolução já feita), rascunho de regras antigas, prazo da Woovi vencido. Com
 * `med.resposta_automatica.incluir_nao_defender` desligado, também os casos fracos
 * (pagador diferente do cliente da cobrança, venda não localizada, KYC não aprovado,
 * IA recomendando não defender).
 */
class RespondDisputesCommand extends Command
{
    protected $signature = 'med:responder
        {--caso=* : Só estes casos (disputes.id)}
        {--incluir-vencidos : Inclui os de prazo interno (72h) vencido que a Woovi ainda aceita}
        {--automatico : Rodada do agendador: inclui os de prazo interno vencido e registra o envio como do sistema}
        {--usuario= : E-mail de quem responde (fica no caso como revisor)}
        {--confirmar : Envia de verdade; sem isto só lista}';

    protected $description = 'Responde na Woovi os MEDs abertos que já têm dossiê e defesa prontos';

    /** Divergência que é dado incoerente: a carta sairia errada. Nunca é enviada. */
    private const INTEGRITY = [
        'value_matches' => 'valor contestado diferente do Pix',
        'sale_amount_matches' => 'valor da venda diferente do Pix (pode ser outro pedido)',
        'no_refund' => 'já houve devolução',
    ];

    /** Caso fraco: só vai com `incluir_nao_defender`. */
    private const WEAK = [
        'payer_is_customer' => 'quem pagou não é o cliente da cobrança',
        'sale_found' => 'venda não localizada',
        'kyc_approved' => 'KYC do lojista não aprovado',
        'no_refund' => 'devoluções não conferidas (Pix sem cobrança)',
    ];

    private const MAX_FAILURES_PER_DAY = 3;

    private const DRAFT_BLOCKER = 'rascunho da IA ausente ou de regras antigas';

    public function handle(SyncDisputeAction $sync, SendEvidenceToWooviAction $send, DiscordNotificationService $discord, DefenseWriter $writer): int
    {
        $automatic = (bool) $this->option('automatico');
        $userId = $this->option('usuario') ? User::query()->where('email', $this->option('usuario'))->value('id') : null;

        if ($this->option('usuario') && $userId === null) {
            $this->error('Usuário não encontrado: '.$this->option('usuario'));

            return self::FAILURE;
        }

        $disputes = Dispute::query()
            ->inWindow()
            ->whereIn('status', [DisputeStatus::PENDING_SELLER->value, DisputeStatus::SELLER_SUBMITTED->value])
            ->when($this->option('caso'), fn ($query, $ids) => $query->whereKey(array_map('intval', $ids)))
            ->with('wooviAccount')
            ->orderBy('respond_by')
            ->get();

        $includeOverdue = $automatic || (bool) $this->option('incluir-vencidos');
        $ready = [];
        $redrafts = 0;

        foreach ($disputes as $dispute) {
            $blockers = self::blockers($dispute, $includeOverdue);

            // Só falta o rascunho (ausente, falhou ou de regras antigas): a rodada automática
            // pede um novo e o caso sai na hora seguinte.
            if ($automatic && $this->option('confirmar') && $blockers === [self::DRAFT_BLOCKER] && $writer->isConfigured()) {
                rescue(function () use ($dispute): void {
                    GenerateAiDefense::dispatch($dispute->id);
                }, report: true);
                $redrafts++;
            }

            if ($blockers === [] || ! $automatic) {
                $this->line(sprintf('#%d %s %s - %s', $dispute->id, $dispute->formattedValue(), $dispute->merchantName() ?? 'sem lojista', $blockers === [] ? 'PRONTO' : 'fica: '.implode('; ', $blockers)));
            }

            if ($blockers === []) {
                $ready[] = $dispute;
            }
        }

        $this->info(now()->timezone('America/Sao_Paulo')->format('d/m H:i').' - '.count($ready).' de '.$disputes->count().' prontos para responder'.($redrafts ? "; {$redrafts} rascunho(s) da IA pedido(s)" : '').'.');

        if (! $this->option('confirmar')) {
            $this->comment('Nada enviado. Rode com --confirmar para responder.');

            return self::SUCCESS;
        }

        $sent = [];
        $failed = [];

        foreach ($ready as $dispute) {
            try {
                // A Woovi pode ter decidido o caso desde a última sincronização.
                $dispute = $sync->handle($dispute)->fresh();

                if (($blockers = self::blockers($dispute, $includeOverdue)) !== []) {
                    $this->warn("#{$dispute->id}: não enviado, mudou na Woovi ({$dispute->woovi_status}): ".implode('; ', $blockers));

                    continue;
                }

                $count = $send->handle($dispute, $userId, $automatic ? 'system' : 'analyst');
                $sent[] = "#{$dispute->id} {$dispute->formattedValue()}";
                $this->info("#{$dispute->id}: respondido ({$count} documento(s)).");
            } catch (Throwable $e) {
                $failed[] = "#{$dispute->id}: {$e->getMessage()}";
                $this->error("#{$dispute->id}: falhou - {$e->getMessage()}");
            }

            usleep(500_000);
        }

        $this->info('Respondidos: '.count($sent).' · falhas: '.count($failed).'.');

        if ($automatic && ($sent !== [] || $failed !== [])) {
            rescue(fn () => $discord->operationAlert(
                'Resposta automática de MED: '.count($sent).' respondido(s), '.count($failed).' falha(s)',
                implode("\n", array_merge($sent, $failed)),
            ), report: false);
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * O que impede responder o caso agora (vazio = pronto).
     *
     * @return array<int, string>
     */
    public static function blockers(Dispute $dispute, bool $includeOverdue = false): array
    {
        $checks = collect((array) data_get($dispute->dossier_match, 'checks', []))->keyBy('key');
        $status = fn (string $key): ?string => $checks[$key]['status'] ?? null;
        $weakAllowed = (bool) config('med.resposta_automatica.incluir_nao_defender');
        $blockers = [];

        if (! in_array($dispute->status, [DisputeStatus::PENDING_SELLER, DisputeStatus::SELLER_SUBMITTED], true)) {
            $blockers[] = 'status '.$dispute->status->label();
        }

        if (strtoupper((string) $dispute->type) !== 'MED') {
            $blockers[] = "não é MED ({$dispute->type})";
        }

        if ($dispute->dossier_match === null || $status('transaction_found') !== MatchDossierAction::OK) {
            $blockers[] = 'transação não conferida na Woovi';
        }

        // Só a divergência é incoerência; o dado que falta (sem venda, Pix sem cobrança)
        // deixa o caso fraco, não errado.
        foreach (self::INTEGRITY as $key => $reason) {
            if ($status($key) === MatchDossierAction::DIVERGENT) {
                $blockers[] = $reason;
            }
        }

        if (! $weakAllowed) {
            foreach (self::WEAK as $key => $reason) {
                if (! in_array($status($key), [MatchDossierAction::OK, MatchDossierAction::DIVERGENT], true)
                    || ($status($key) === MatchDossierAction::DIVERGENT && ! array_key_exists($key, self::INTEGRITY))) {
                    $blockers[] = $reason;
                }
            }

            if (! in_array(data_get($dispute->ai_defense, 'recommendation'), ['defender', 'defender_com_ressalvas'], true)) {
                $blockers[] = 'IA não recomenda defender';
            }
        }

        if ($dispute->ai_defense === null || (int) data_get($dispute->ai_defense, 'rules_version', 1) < GenerateAiDefenseAction::RULES_VERSION) {
            $blockers[] = self::DRAFT_BLOCKER;
        }

        if (blank($dispute->defense_text)) {
            $blockers[] = 'sem texto de defesa';
        }

        if (! $includeOverdue && $dispute->respond_by?->isPast()) {
            $blockers[] = 'prazo interno vencido';
        }

        if ($dispute->opened_at?->copy()->addHours((int) config('med.prazo_woovi_horas'))->isPast()) {
            $blockers[] = 'prazo da Woovi expirado';
        }

        $failures = $dispute->events()
            ->where('event', DisputeEventType::EVIDENCE_SEND_FAILED->value)
            ->where('level', 'error')
            ->where('occurred_at', '>=', now()->subDay())
            ->pluck('message');

        if ($failures->contains(fn (string $message): bool => str_contains(mb_strtolower($message), 'prazo'))) {
            $blockers[] = 'a Woovi recusou por prazo expirado';
        } elseif ($failures->count() >= self::MAX_FAILURES_PER_DAY) {
            $blockers[] = 'falhou '.$failures->count().' vezes nas últimas 24h - ver a ficha';
        }

        // Política da loja sendo capturada agora: espera para a resposta sair com ela.
        $store = $dispute->merchantStore();

        if ($store && filled($store->website) && $store->captured_at === null && $store->updated_at?->gt(now()->subHours(2))) {
            $blockers[] = 'captura das políticas da loja em andamento';
        }

        return $blockers;
    }
}
