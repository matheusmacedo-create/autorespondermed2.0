<?php

namespace App\Actions\Med;

use App\Enums\DisputeEventType;
use App\Models\Dispute;
use App\Services\DisputeEventRecorder;
use App\Services\Woovi\WooviClient;
use App\Services\Woovi\WooviException;

/**
 * Relê um caso na Woovi (GET /dispute/{endToEndId}) e aplica o que mudou.
 *
 * O GET só aceita o endToEndId e, quando o Pix tem mais de uma disputa (MED e
 * chargeback, MED cancelado e outro aberto depois), devolve a mais antiga. Se ela não
 * for a deste caso, a releitura vem da listagem do dia em que o caso abriu - aplicar a
 * outra trocaria o tipo e o status deste caso a cada sincronização.
 */
class SyncDisputeAction
{
    public function __construct(
        private readonly IngestDisputeAction $ingest,
        private readonly DisputeEventRecorder $events,
    ) {}

    public function handle(Dispute $dispute): Dispute
    {
        $dispute->loadMissing('wooviAccount');
        $client = WooviClient::for($dispute->wooviAccount);

        try {
            $payload = $client->getDispute($dispute->end_to_end_id);

            if ($this->isAnotherDispute($dispute, $payload)) {
                $payload = $this->fromList($client, $dispute);
            }
        } catch (WooviException $e) {
            $this->events->record(DisputeEventType::SYNC_FAILED, $dispute, $e->getMessage(), ['status' => $e->status], level: 'error');

            throw $e;
        }

        if ($payload === []) {
            return $dispute;
        }

        $payload['endToEndId'] ??= $dispute->end_to_end_id;

        // Releitura sem novidade não vira evento (seria um por hora na linha do tempo):
        // o que mudou a ingestão já registra (status na Woovi, encerramento).
        $this->ingest->handle($dispute->wooviAccount, $payload, 'sync');

        return $dispute->fresh() ?? $dispute;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isAnotherDispute(Dispute $dispute, array $payload): bool
    {
        $id = self::wooviId($payload);

        return $dispute->woovi_dispute_id !== null && $id !== null && $id !== $dispute->woovi_dispute_id;
    }

    /**
     * A disputa deste caso na listagem de um dia antes a um dia depois da abertura (o
     * filtro da listagem é pela criação na Woovi). Vazio se não estiver lá.
     *
     * @return array<string, mixed>
     */
    private function fromList(WooviClient $client, Dispute $dispute): array
    {
        $openedAt = $dispute->opened_at ?? $dispute->created_at;

        $disputes = $client->listDisputes(
            start: $openedAt->copy()->subDay()->toIso8601ZuluString(),
            end: $openedAt->copy()->addDay()->toIso8601ZuluString(),
        );

        foreach ($disputes as $item) {
            if (is_array($item) && self::wooviId($item) === $dispute->woovi_dispute_id) {
                return $item;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function wooviId(array $payload): ?string
    {
        $id = $payload['id'] ?? $payload['_id'] ?? null;

        return is_scalar($id) && trim((string) $id) !== '' ? trim((string) $id) : null;
    }
}
