<?php

namespace App\Actions\Med;

use App\Enums\WooviAccountSource;
use App\Models\WooviAccount;
use App\Services\Woovi\WooviClient;
use App\Services\Woovi\WooviException;
use Closure;
use Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Importa as subcontas Woovi dos sellers pela API de parceiro, com a conta mestre
 * da ÚnicoPag: lista as subcontas (afiliadas + pré-cadastros) e, para cada CNPJ
 * que ainda não tem conta aqui, cria na subconta um aplicativo só com os escopos
 * do MED e guarda o AppID.
 *
 * O POST /partner/application não é idempotente: cada chamada deixa mais uma
 * credencial na subconta do seller. Por isso só cria quando consegue provar que
 * a empresa não existe aqui (conta ativa sem CPF/CNPJ trava a criação), conta
 * existente nunca ganha chave nova (só os campos em branco são completados), e
 * cada CNPJ é tratado sob trava (o PUT do unico-api respeita a mesma).
 */
class ImportPartnerAccountsAction
{
    private const PAGE = 100;

    private const MAX_PAGES = 500;

    /**
     * @param  Closure(string, string): void|null  $report  recebe (info|warn|error, mensagem)
     * @return array{found: int, created: int, existing: int, skipped: int, failed: int, created_ids: array<int, int>, aborted: ?string}
     */
    public function handle(?Closure $report = null): array
    {
        $report ??= fn (string $level, string $message) => null;
        $scopes = $this->scopes();
        $client = WooviClient::master();

        [$companies, $skipped] = $this->companies($client, $report);

        $summary = ['found' => count($companies), 'created' => 0, 'existing' => 0, 'skipped' => $skipped, 'failed' => 0, 'created_ids' => [], 'aborted' => null];

        // Conta sem CPF/CNPJ nem id da empresa não dá para casar com a subconta: criar
        // chave nova seria duplicar a conta do seller (dois casos por MED, dois avisos).
        $unidentified = WooviAccount::query()->where('is_active', true)->whereNull('tax_id')->whereNull('woovi_company_id')->pluck('name');

        if ($unidentified->isNotEmpty()) {
            $summary['aborted'] = 'contas ativas sem CPF/CNPJ (preencha antes de importar): '.$unidentified->take(20)->implode(', ')
                .($unidentified->count() > 20 ? ' e mais '.($unidentified->count() - 20) : '');
        }

        foreach ($companies as $taxId => $company) {
            $outcome = Cache::lock(self::lockKey($taxId), 60)->block(10, function () use ($client, $company, $taxId, $scopes, $summary): array {
                if ($existing = $this->existing($taxId, $company['woovi_company_id'])) {
                    $this->fillBlanks($existing, $company);

                    return ['existing'];
                }

                if ($summary['aborted'] !== null) {
                    return ['skip'];
                }

                try {
                    $application = $client->createPartnerApplication($taxId, (string) config('services.woovi.partner_application_name', 'UnicoPag MED'), $scopes);
                } catch (WooviException $e) {
                    // Mesma recusa para todas as subcontas: credencial, feature, escopo
                    // ou limite. Parar aqui evita centenas de chamadas iguais por hora.
                    if (in_array($e->status, [401, 403, 429], true) || ($e->status === 400 && str_contains(strtolower((string) $e->body), 'scope'))) {
                        return ['abort', $e->getMessage()];
                    }

                    return ['failed', $e->getMessage()];
                }

                $clientId = (string) ($application['clientId'] ?? '');
                $secret = (string) ($application['clientSecret'] ?? '');

                if ($clientId === '' || $secret === '') {
                    return ['failed', 'a Woovi criou o aplicativo sem clientId/clientSecret.'];
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

                return ['created', $account->id];
            });

            match ($outcome[0]) {
                'existing' => $summary['existing']++,
                'created' => (function () use (&$summary, $outcome, $company, $report): void {
                    $summary['created']++;
                    $summary['created_ids'][] = $outcome[1];
                    $report('info', "{$company['name']}: conta criada, com aplicativo \"".config('services.woovi.partner_application_name').'" na subconta.');
                })(),
                'failed' => (function () use (&$summary, $outcome, $company, $report): void {
                    $summary['failed']++;
                    $report('error', "{$company['name']}: {$outcome[1]}");
                    Log::warning('MED: importação da subconta Woovi falhou.', ['account' => $company['name'], 'error' => $outcome[1]]);
                })(),
                'abort' => $summary['aborted'] = $outcome[1],
                default => null,
            };
        }

        return $summary;
    }

    public static function lockKey(string $taxId): string
    {
        return 'med:woovi-account:'.preg_replace('/\D/', '', $taxId);
    }

    /**
     * @return array<int, string>
     */
    private function scopes(): array
    {
        $scopes = array_values((array) config('services.woovi.partner_application_scopes', []));

        if ($scopes === [] || collect($scopes)->contains(fn ($scope): bool => ! is_string($scope) || ! preg_match('/^[A-Z][A-Z_]+$/', $scope))) {
            throw new RuntimeException('WOOVI_PARTNER_APPLICATION_SCOPES inválida: liste os escopos separados por vírgula (ex.: DISPUTE_GET,DISPUTE_POST).');
        }

        return $scopes;
    }

    private function existing(string $taxId, ?string $companyId): ?WooviAccount
    {
        return WooviAccount::query()
            ->where('tax_id', $taxId)
            ->when($companyId !== null, fn ($query) => $query->orWhere('woovi_company_id', $companyId))
            ->first();
    }

    /**
     * @param  array{woovi_company_id: ?string, contact_name: ?string, contact_email: ?string, contact_phone: ?string}  $company
     */
    private function fillBlanks(WooviAccount $account, array $company): void
    {
        $account->fill(array_filter([
            'woovi_company_id' => $account->woovi_company_id ?: $company['woovi_company_id'],
            'contact_name' => $account->contact_name ?: $company['contact_name'],
            'contact_email' => $account->contact_email ?: $company['contact_email'],
            'contact_phone' => $account->contact_phone ?: $company['contact_phone'],
        ]));

        if ($account->isDirty()) {
            $account->save();
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
