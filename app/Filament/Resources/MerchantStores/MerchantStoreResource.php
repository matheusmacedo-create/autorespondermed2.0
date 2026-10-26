<?php

namespace App\Filament\Resources\MerchantStores;

use App\Actions\Med\MerchantStoreAction;
use App\Enums\DisputeStatus;
use App\Filament\Resources\MerchantStores\Pages\ListMerchantStores;
use App\Jobs\CaptureMerchantStore;
use App\Models\MerchantStore;
use App\Services\Stores\StoreCapturer;
use App\Support\Permissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * As lojas dos lojistas que tiveram MED: o site e as políticas capturadas. O
 * analista informa o site que falta (ou a página de entrega, quando a busca não
 * acha) e a captura roda sozinha.
 */
class MerchantStoreResource extends Resource
{
    protected static ?string $model = MerchantStore::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::GlobeAlt;

    protected static ?string $navigationLabel = 'Lojas';

    protected static ?string $modelLabel = 'loja';

    protected static ?string $pluralModelLabel = 'lojas';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationBadge(): ?string
    {
        $count = MerchantStore::query()->whereNull('website')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Lojas sem site: informe para capturar as políticas';
    }

    public static function table(Table $table): Table
    {
        $open = fn (Builder $query): Builder => $query->inWindow()->whereIn('status', [DisputeStatus::PENDING_SELLER->value, DisputeStatus::SELLER_SUBMITTED->value]);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['disputes as open_disputes_count' => $open]))
            ->columns([
                TextColumn::make('name')
                    ->label('Lojista')
                    ->description(fn (MerchantStore $record): string => 'conta '.$record->merchant_account_id)
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('website')
                    ->label('Site')
                    ->state(fn (MerchantStore $record): string => $record->website ? preg_replace('#^https?://(www\.)?#', '', rtrim($record->website, '/')) : 'Sem site')
                    ->url(fn (MerchantStore $record): ?string => $record->website)
                    ->openUrlInNewTab()
                    ->color(fn (MerchantStore $record): string => $record->website ? 'primary' : 'danger')
                    ->limit(36)
                    ->description(fn (MerchantStore $record): string => match ($record->website_source) {
                        'unicopag_register' => 'pelo cadastro da plataforma',
                        'kyc' => 'pelo KYC',
                        'sale' => 'pela venda',
                        'manual' => 'informado no painel',
                        default => 'não está no KYC nem no cadastro',
                    })
                    ->searchable(),
                TextColumn::make('capture_status')
                    ->label('Políticas')
                    ->badge()
                    ->state(fn (MerchantStore $record): string => self::statusLabel($record))
                    ->color(fn (MerchantStore $record): string => match (true) {
                        $record->capture_status === 'ok' => 'success',
                        $record->capture_status === 'partial' => 'warning',
                        blank($record->website) => 'gray',
                        $record->capture_status === 'failed' => 'danger',
                        default => 'info',
                    })
                    ->description(fn (MerchantStore $record): ?string => collect((array) $record->captures)
                        ->map(fn (array $capture): string => MerchantStore::SHORT[$capture['kind']] ?? $capture['kind'])
                        ->implode(' · ') ?: null)
                    ->tooltip(fn (MerchantStore $record): ?string => $record->capture_error),
                TextColumn::make('captured_at')
                    ->label('Capturado')
                    ->since()
                    ->dateTimeTooltip('d/m/Y H:i', 'America/Sao_Paulo')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('open_disputes_count')
                    ->label('MEDs abertos')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('situacao')
                    ->label('Situação')
                    ->options(['sem_site' => 'Sem site', 'sem_politica' => 'Sem política no site', 'com_politica' => 'Com política'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'sem_site' => $query->whereNull('website'),
                        'sem_politica' => $query->whereNotNull('website')->where('capture_status', 'failed'),
                        'com_politica' => $query->whereIn('capture_status', ['ok', 'partial']),
                        default => $query,
                    }),
            ])
            ->defaultSort('open_disputes_count', 'desc')
            ->recordActions([
                ActionGroup::make(collect(MerchantStore::KINDS)
                    ->map(fn (string $label, string $kind): Action => Action::make("open_{$kind}")
                        ->label($label)
                        ->icon('heroicon-o-document-text')
                        ->url(fn (MerchantStore $record): string => route('admin.store.capture', [$record, $kind]))
                        ->openUrlInNewTab()
                        ->visible(fn (MerchantStore $record): bool => $record->capture($kind) !== null))
                    ->values()
                    ->all())
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->button()
                    ->color('gray')
                    ->visible(fn (MerchantStore $record): bool => (array) $record->captures !== []),
                self::websiteAction(),
                self::captureAction(),
            ])
            ->recordAction('website');
    }

    public static function statusLabel(MerchantStore $store): string
    {
        return match (true) {
            blank($store->website) => 'Sem site',
            $store->capture_status === 'ok' => 'Capturadas',
            $store->capture_status === 'partial' => 'Parcial',
            $store->capture_status === 'failed' && $store->captured_at !== null => 'Sem política no site',
            $store->capture_status === 'failed' => 'Erro na captura',
            default => 'Aguardando captura',
        };
    }

    public static function websiteAction(): Action
    {
        return Action::make('website')
            ->label(fn (MerchantStore $record): string => $record->website ? 'Editar site' : 'Informar site')
            ->icon('heroicon-o-pencil-square')
            ->color(fn (MerchantStore $record): string => $record->website ? 'gray' : 'primary')
            ->button()
            ->modalHeading(fn (MerchantStore $record): string => "Site da loja - {$record->name}")
            ->modalDescription('A captura abre o site, acha as páginas de entrega, termos e trocas e guarda cada uma em PDF nos MEDs da loja. Informe a página específica só quando a busca não achar.')
            ->modalSubmitActionLabel('Salvar e capturar')
            ->fillForm(fn (MerchantStore $record): array => $record->only(['website', 'shipping_url', 'exchange_url', 'terms_url']))
            ->schema([
                TextInput::make('website')->label('Site')->placeholder('https://loja.com.br')->required()->maxLength(500),
                TextInput::make('shipping_url')->label('Página de entrega (opcional)')->url()->maxLength(500),
                TextInput::make('exchange_url')->label('Página de trocas (opcional)')->url()->maxLength(500),
                TextInput::make('terms_url')->label('Termos de uso (opcional)')->url()->maxLength(500),
            ])
            ->visible(fn (): bool => Permissions::allows(Permissions::REVIEW_DISPUTES))
            ->action(function (array $data, MerchantStore $record, StoreCapturer $capturer): void {
                $website = MerchantStoreAction::normalizeUrl($data['website']);

                if ($website === null) {
                    Notification::make()->danger()->title('Site inválido')->body('Informe o endereço da loja, ex.: https://loja.com.br')->send();

                    return;
                }

                $record->forceFill([
                    'website' => $website,
                    'website_source' => $website === $record->website ? $record->website_source : 'manual',
                    'shipping_url' => $data['shipping_url'] ?: null,
                    'exchange_url' => $data['exchange_url'] ?: null,
                    'terms_url' => $data['terms_url'] ?: null,
                    'capture_status' => null,
                    'capture_error' => null,
                ])->save();

                if ($capturer->isAvailable()) {
                    CaptureMerchantStore::dispatch($record->id);
                }

                Notification::make()->success()->title('Site salvo')->body('A captura das políticas leva alguns minutos.')->send();
            });
    }

    public static function captureAction(): Action
    {
        return Action::make('capture')
            ->label('Capturar de novo')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->iconButton()
            ->tooltip('Capturar as políticas de novo')
            ->visible(fn (MerchantStore $record): bool => Permissions::allows(Permissions::REVIEW_DISPUTES) && filled($record->website))
            ->action(function (MerchantStore $record, StoreCapturer $capturer): void {
                if (! $capturer->isAvailable()) {
                    Notification::make()->danger()->title('Captura indisponível')->body('O navegador não está instalado no servidor (doc/DEPLOY_FORGE.md).')->send();

                    return;
                }

                CaptureMerchantStore::dispatch($record->id);
                Notification::make()->success()->title('Captura pedida')->body('Leva alguns minutos; os PDFs entram nos MEDs abertos desta loja.')->send();
            });
    }

    public static function canViewAny(): bool
    {
        return Permissions::allows(Permissions::VIEW_DISPUTES);
    }

    public static function canCreate(): bool
    {
        // A loja nasce do MED (lojista identificado pela venda).
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMerchantStores::route('/'),
        ];
    }
}
