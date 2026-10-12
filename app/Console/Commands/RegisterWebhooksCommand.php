<?php

namespace App\Console\Commands;

use App\Actions\Med\RegisterWooviWebhooksAction;
use App\Models\WooviAccount;
use Illuminate\Console\Command;
use Throwable;

class RegisterWebhooksCommand extends Command
{
    protected $signature = 'med:registrar-webhooks {--conta= : ID da conta Woovi; sem ela, todas as ativas}';

    protected $description = 'Cadastra os webhooks OPENPIX:DISPUTE_* nas contas Woovi';

    public function handle(RegisterWooviWebhooksAction $register): int
    {
        $accounts = WooviAccount::query()
            ->where('is_active', true)
            ->when($this->option('conta'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        $failures = 0;

        foreach ($accounts as $account) {
            try {
                $created = $register->handle($account);
                $this->line("{$account->name}: ".($created === [] ? 'já cadastrados' : implode(', ', $created)));
            } catch (Throwable $e) {
                $failures++;
                $this->error("{$account->name}: {$e->getMessage()}");
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
