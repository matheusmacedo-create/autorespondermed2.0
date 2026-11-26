<?php

namespace App\Actions\Med;

use App\Models\Dispute;
use App\Models\WooviAccount;
use App\Services\Transfeera\InfractionMapper;

/**
 * Infração da Transfeera (webhook `Infraction` ou `GET /med/infractions`) -> caso. A
 * ingestão é a mesma da Woovi: o {@see InfractionMapper} traduz os campos.
 */
class IngestTransfeeraInfractionAction
{
    public function __construct(private readonly IngestDisputeAction $ingest) {}

    /**
     * @param  array<string, mixed>  $infraction
     */
    public function handle(WooviAccount $account, array $infraction, string $source = 'webhook'): Dispute
    {
        return $this->ingest->handle($account, InfractionMapper::toDisputePayload($infraction), $source, 'Infraction');
    }
}
