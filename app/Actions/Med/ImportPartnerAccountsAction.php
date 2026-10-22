<?php

namespace App\Actions\Med;

use App\Enums\WooviAccountSource;
use App\Models\WooviAccount;
use App\Services\Woovi\WooviClient;
use App\Services\Woovi\WooviException;
use Closure;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * Importa as subcontas Woovi dos sellers pela API de parceiro, com a conta mestre
 * da ÚnicoPag: lista as subcontas (afiliadas + pré-cadastros) e, para cada CNPJ
 * que ainda não tem conta aqui, cria na subconta um aplicativo só com os escopos
 * do MED e guarda o AppID. Conta que já existe (de qualquer origem) não ganha
 * chave nova: o aplicativo da Woovi não é idempotente, e cada chamada criaria
 * outra credencial na subconta do seller - só os campos em branco são completados.
 */
class ImportPartnerAccountsAction
{
    private const PAGE = 100;

    private const MAX_PAGES = 500;

    /**
     * @param  Closure(string, string): void|null  $report  recebe (info|warn|error, mensagem)
     * @return array{found: int, created: int, existing: int, skipped: int, failed: int, created_ids: array<int, int>}
     */
    public function handle(?Closure $report = null): array
    {
        $report ??= fn (string $level, string $message) => null;
        $client = WooviClient::master();

        [$companies, $skipped] = $this->companies($client, $report);

        $summary = ['found' => count($companies), 'created' => 0, 'existing' => 0, 'skipped' => $skipped, 'failed' => 0, 'created_ids' => []];

        foreach ($companies as $taxId => $company) {
            $query = WooviAccount::query()->where('tax_id', $taxId);

            if ($company['woovi_company_id'] !== null) {
                $query->orWhere('woovi_company_id', $company['woovi_company_id']);
            }

            if ($existing = $query->first()) {
                $existing->fill(array_filter([
                    'woovi_company_id' => $existing->woovi_company_id ?: $company['woovi_company_id'],
                    'contact_name' => $existing->contact_name ?: $company['contact_name'],
                    'contact_email' => $existing->contact_email ?: $company['contact_email'],
                    'contact_phone' => $existing->contact_phone ?: $company['contact_phone'],
                ]));

                if ($existing->isDirty()) {
                    $existing->save();
                }

                $summary['existing']++;

                continue;
            }

            try {
                $application = $this->createApplication($client, $taxId, $report);
            } catch (WooviException $e) {
                $summary['failed']++;
                $report('error', "{$company['name']} ({$taxId}): {$e->getMessage()}");
                Log::warning('MED: importação da subconta Woovi falhou.', ['tax_id' => $taxId, 'error' => $e->getMessage()]);

                continue;
            }

            $clientId = (string) ($application['clientId'] ?? '');
            $secret = (string) ($application['clientSecret'] ?? '');

            if ($clientId === '' || $secret === '') {
                $summary['failed']++;
                $report('error', "{$company['name']} ({$taxId}): a Woovi criou o aplicativo sem clientId/clientSecret.");

                continue;
            }

            $account = WooviAccount::query()->create([
                'name' => $company['name'],
                'tax_id' => $taxId,
                'woovi_company_id' => $company['woovi_company_id'],
                'app_id' => base64_encode($clientId.':'.$secret),
                'source' => WooviAccountSource::PARTNER,
                'contact_name' => $company['contact_name'],
                'contact_email' => $company['contact_email'],
                'contact_phone' => $company['contact_phone'],
                'is_active' => true,
            ]);

            $summary['created']++;
            $summary['created_ids'][] = $account->id;
            $report('info', "{$company['name']} ({$taxId}): conta criada, com aplicativo \"".config('services.woovi.partner_application_name').'" na subconta.');
        }

        return $summary;
    }

    /**
     * Cria com os escopos do MED; se a Woovi recusar a lista (400 citando escopo),
     * cria sem escopos - acesso completo, como a chave que o unico-api já tem.
     *
     * @return array<string, mixed>
     */
    private function createApplication(WooviClient $client, string $taxId, Closure $report): array
    {
        $name = (string) config('services.woovi.partner_application_name', 'UnicoPag MED');
        $scopes = array_values((array) config('services.woovi.partner_application_scopes', []));

        try {
            return $client->createPartnerApplication($taxId, $name, $scopes);
        } catch (WooviException $e) {
            if ($scopes === [] || $e->status !== 400 || ! str_contains(strtolower((string) $e->body), 'scope')) {
                throw $e;
            }

            $report('warn', "{$taxId}: a Woovi recusou a lista de escopos ({$e->getMessage()}); criando sem escopos.");

            return $client->createPartnerApplication($taxId, $name);
        }
    }

    /**
     * Subcontas da conta mestre, por CNPJ (só dígitos). As afiliadas trazem o id da
     * empresa; o pré-cadastro, o contato do usuário mestre. Pré-cadastro cuja empresa
     * ainda não existe na Woovi (cadastro não concluído) é pulado: não há subconta
     * onde criar o aplicativo.
     *
     * @return array{0: array<string, array{name: string, woovi_company_id: ?string, contact_name: ?string, contact_email: ?string, contact_phone: ?string}>, 1: int}
     */
    private function companies(WooviClient $client, Closure $report): array
    {
        $companies = [];
        $skipped = 0;

        foreach ($this->paginate(fn (int $skip): array => $client->listAffiliates($skip, self::PAGE), 'affiliates') as $affiliate) {
            $company = (array) ($affiliate['company'] ?? []);
            $taxId = $this->digits($company['taxID'] ?? null);

            if ($taxId === '') {
                $skipped++;

                continue;
            }

            $companies[$taxId] = [
                'name' => (string) (($company['name'] ?? null) ?: 'Subconta '.$taxId),
                'woovi_company_id' => filled($company['id'] ?? null) ? (string) $company['id'] : null,
                'contact_name' => null,
                'contact_email' => null,
                'contact_phone' => null,
            ];
        }

        try {
            foreach ($this->paginate(fn (int $skip): array => $client->listPreRegistrations($skip, self::PAGE), 'preRegistrations') as $item) {
                $pre = (array) ($item['preRegistration'] ?? []);
                $company = (array) ($item['company'] ?? []);
                $user = (array) ($item['user'] ?? []);
                $taxId = $this->digits($company['taxID'] ?? ($pre['taxID'] ?? null));

                if ($taxId === '' || blank($company['id'] ?? null)) {
                    $skipped++;

                    continue;
                }

                $current = $companies[$taxId] ?? ['name' => '', 'woovi_company_id' => null];
                $contactName = trim((string) ($user['firstName'] ?? '').' '.(string) ($user['lastName'] ?? ''));

                $companies[$taxId] = [
                    'name' => $current['name'] ?: (string) (($company['name'] ?? null) ?: (($pre['name'] ?? null) ?: 'Subconta '.$taxId)),
                    'woovi_company_id' => $current['woovi_company_id'] ?: (string) $company['id'],
                    'contact_name' => $contactName !== '' ? $contactName : null,
                    'contact_email' => filled($user['email'] ?? null) ? (string) $user['email'] : null,
                    'contact_phone' => filled($user['phone'] ?? null) ? (string) $user['phone'] : null,
                ];
            }
        } catch (WooviException $e) {
            // Conta mestre sem pré-cadastro de parceiro: as afiliadas bastam.
            $report('warn', 'Pré-cadastros não lidos: '.$e->getMessage());
        }

        return [$companies, $skipped];
    }

    /**
     * @param  Closure(int): array<string, mixed>  $page
     * @return Generator<int, array<string, mixed>>
     */
    private function paginate(Closure $page, string $key): Generator
    {
        $skip = 0;

        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $result = $page($skip);
            $items = (array) ($result[$key] ?? []);

            foreach ($items as $item) {
                if (is_array($item)) {
                    yield $item;
                }
            }

            if ($items === [] || ! ($result['hasNextPage'] ?? false)) {
                break;
            }

            $skip += self::PAGE;
        }
    }

    /**
     * O taxID vem como string ou como {taxID, type}, conforme a rota.
     */
    private function digits(mixed $taxId): string
    {
        if (is_array($taxId)) {
            $taxId = $taxId['taxID'] ?? '';
        }

        return (string) preg_replace('/\D/', '', (string) $taxId);
    }
}
