<?php

namespace App\Console\Commands;

use App\Actions\Med\GenerateAiDefenseAction;
use App\Actions\Med\MatchDossierAction;
use App\Actions\Med\SendEvidenceToWooviAction;
use App\Actions\Med\SyncDisputeAction;
use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * Responde em lote os MEDs abertos que já têm tudo: conferência pronta, venda
 * localizada com o valor do Pix, nenhuma devolução, pagador igual ao cliente da
 * cobrança e defesa da IA (versão atual das regras) recomendando defender. Relê
 * cada caso na Woovi antes de enviar. Sem --confirmar só lista o que faria.
 */
class RespondDisputesCommand extends Command
{
    protected $signature = 'med:responder
        {--caso=* : Só estes casos (disputes.id)}
        {--incluir-vencidos : Inclui os de prazo interno vencido que seguem abertos na Woovi}
        {--usuario= : E-mail de quem responde (fica no caso como revisor)}
        {--confirmar : Envia de verdade; sem isto só lista}';

    protected $description = 'Responde na Woovi os MEDs abertos que já têm dossiê e defesa prontos';

    private const CHECKS = [
        'sale_found' => 'venda não localizada',
        'sale_amount_matches' => 'valor da venda diferente do Pix',
        'no_refund' => 'já houve devolução',
        'payer_is_customer' => 'quem pagou não é o cliente da cobrança',
    ];

    public function handle(SyncDisputeAction $sync, SendEvidenceToWooviAction $send): int
    {
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

        $includeOverdue = (bool) $this->option('incluir-vencidos');
        $ready = [];

        foreach ($disputes as $dispute) {
            $blockers = self::blockers($dispute, $includeOverdue);
            $this->line(sprintf('#%d %s %s - %s', $dispute->id, $dispute->formattedValue(), $dispute->merchantName() ?? 'sem lojista', $blockers === [] ? 'PRONTO' : 'fica: '.implode('; ', $blockers)));

            if ($blockers === []) {
                $ready[] = $dispute;
            }
        }

        $this->info(count($ready).' de '.$disputes->count().' prontos para responder.');

        if (! $this->option('confirmar')) {
            $this->comment('Nada enviado. Rode com --confirmar para responder.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($ready as $dispute) {
            try {
                // A Woovi pode ter decidido o caso desde a última sincronização.
                $dispute = $sync->handle($dispute)->fresh();

                if (($blockers = self::blockers($dispute, $includeOverdue)) !== []) {
                    $this->warn("#{$dispute->id}: não enviado, mudou na Woovi ({$dispute->woovi_status}): ".implode('; ', $blockers));

                    continue;
                }

                $count = $send->handle($dispute, $userId);
                $sent++;
                $this->info("#{$dispute->id}: respondido ({$count} documento(s)).");
            } catch (Throwable $e) {
                $failed++;
                $this->error("#{$dispute->id}: falhou - {$e->getMessage()}");
            }

            usleep(500_000);
        }

        $this->info("Respondidos: {$sent} · falhas: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * O que impede responder o caso agora (vazio = pronto).
     *
     * @return array<int, string>
     */
    public static function blockers(Dispute $dispute, bool $includeOverdue = false): array
    {
        $checks = collect((array) data_get($dispute->dossier_match, 'checks', []))->keyBy('key');
        $blockers = [];

        if (! in_array($dispute->status, [DisputeStatus::PENDING_SELLER, DisputeStatus::SELLER_SUBMITTED], true)) {
            $blockers[] = 'status '.$dispute->status->label();
        }

        if (strtoupper((string) $dispute->type) !== 'MED') {
            $blockers[] = "não é MED ({$dispute->type})";
        }

        if (! data_get($dispute->dossier_match, 'ready')) {
            $blockers[] = 'conferência com divergência';
        }

        foreach (self::CHECKS as $key => $reason) {
            if (($checks[$key]['status'] ?? null) !== MatchDossierAction::OK) {
                $blockers[] = $reason;
            }
        }

        if (! in_array(data_get($dispute->ai_defense, 'recommendation'), ['defender', 'defender_com_ressalvas'], true)) {
            $blockers[] = 'IA não recomenda defender';
        } elseif ((int) data_get($dispute->ai_defense, 'rules_version', 1) < GenerateAiDefenseAction::RULES_VERSION) {
            $blockers[] = 'rascunho da IA de regras antigas';
        }

        if (blank($dispute->defense_text)) {
            $blockers[] = 'sem texto de defesa';
        }

        if (! $includeOverdue && $dispute->respond_by?->isPast()) {
            $blockers[] = 'prazo interno vencido';
        }

        return $blockers;
    }
}
