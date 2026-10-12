<?php

namespace App\Services;

use App\Enums\DisputeStatus;
use App\Models\Dispute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Alertas da operação de MED no Discord. Sem webhook configurado vira log -
 * e nunca lança: alarme que quebra o fluxo é pior que alarme que falta.
 */
class DiscordNotificationService
{
    private const COLOR_INFO = 3447003;

    private const COLOR_WARNING = 15105570;

    private const COLOR_DANGER = 15158332;

    private const COLOR_SUCCESS = 3066993;

    public function disputeReceived(Dispute $dispute): void
    {
        $this->send(
            content: 'Novo MED recebido.',
            title: "MED recebido: {$dispute->formattedValue()} - {$dispute->wooviAccount->name}",
            dispute: $dispute,
            color: self::COLOR_WARNING,
            mention: true,
        );
    }

    public function sellerSubmitted(Dispute $dispute): void
    {
        $this->send(
            content: 'Seller enviou a defesa - caso na fila de análise.',
            title: "Defesa recebida: {$dispute->formattedValue()} - {$dispute->wooviAccount->name}",
            dispute: $dispute,
            color: self::COLOR_INFO,
            mention: true,
        );
    }

    public function evidenceSendFailed(Dispute $dispute, string $error): void
    {
        $this->send(
            content: 'Falha ao enviar as evidências à Woovi.',
            title: "Envio falhou: {$dispute->formattedValue()} - {$dispute->wooviAccount->name}",
            dispute: $dispute,
            color: self::COLOR_DANGER,
            mention: true,
            extraFields: [['name' => 'Erro', 'value' => Str::limit($error, 1000)]],
        );
    }

    public function disputeClosed(Dispute $dispute): void
    {
        $won = $dispute->status === DisputeStatus::REJECTED;

        $this->send(
            content: 'MED encerrado pela Woovi.',
            title: "{$dispute->status->label()}: {$dispute->formattedValue()} - {$dispute->wooviAccount->name}",
            dispute: $dispute,
            color: $won ? self::COLOR_SUCCESS : self::COLOR_DANGER,
            mention: false,
        );
    }

    /**
     * @param  iterable<Dispute>  $disputes
     */
    public function deadlineDigest(iterable $disputes): void
    {
        $lines = collect($disputes)
            ->map(fn (Dispute $dispute): string => sprintf(
                '- %s - %s - %s (vence %s)',
                $dispute->wooviAccount->name,
                $dispute->formattedValue(),
                $dispute->status->label(),
                $dispute->respond_by?->timezone('America/Sao_Paulo')->format('d/m H:i') ?? '—',
            ));

        if ($lines->isEmpty()) {
            return;
        }

        $this->post([
            'content' => trim($this->roleMention().' MEDs com prazo vencendo ('.$lines->count().')'),
            'embeds' => [[
                'title' => 'Prazos de MED vencendo',
                'url' => rtrim((string) config('app.url'), '/').'/admin/disputes',
                'color' => self::COLOR_DANGER,
                'description' => Str::limit($lines->implode("\n"), 4000),
            ]],
        ]);
    }

    /**
     * @param  array<int, array<string, string>>  $extraFields
     */
    private function send(string $content, string $title, Dispute $dispute, int $color, bool $mention, array $extraFields = []): void
    {
        $fields = [
            ['name' => 'Conta', 'value' => Str::limit($dispute->wooviAccount->name, 200), 'inline' => true],
            ['name' => 'Valor', 'value' => $dispute->formattedValue(), 'inline' => true],
            ['name' => 'Status', 'value' => $dispute->status->label(), 'inline' => true],
            ['name' => 'Motivo', 'value' => Str::limit($dispute->dispute_reason ?: '—', 200), 'inline' => true],
            ['name' => 'Prazo', 'value' => $dispute->respond_by?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '—', 'inline' => true],
            ['name' => 'endToEndId', 'value' => $dispute->end_to_end_id],
            ...$extraFields,
        ];

        $this->post([
            'content' => trim(($mention ? $this->roleMention().' ' : '').$content),
            'embeds' => [[
                'title' => Str::limit($title, 250),
                'url' => rtrim((string) config('app.url'), '/')."/admin/disputes/{$dispute->id}",
                'color' => $color,
                'fields' => $fields,
            ]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(array $payload): void
    {
        $webhookUrl = (string) config('services.discord.med_webhook_url');

        if ($webhookUrl === '') {
            Log::info('Discord (MED) não configurado - alerta só no log.', ['payload' => $payload]);

            return;
        }

        try {
            $response = Http::timeout(5)->post($webhookUrl, $payload);

            if (! $response->successful()) {
                Log::warning('Discord (MED) recusou o alerta.', ['status' => $response->status(), 'body' => $response->body()]);
            }
        } catch (Throwable $e) {
            Log::warning('Falha ao enviar alerta ao Discord (MED).', ['error' => $e->getMessage()]);
        }
    }

    private function roleMention(): string
    {
        $roleId = (string) config('services.discord.med_role_id');

        return $roleId !== '' ? "<@&{$roleId}>" : '';
    }
}
