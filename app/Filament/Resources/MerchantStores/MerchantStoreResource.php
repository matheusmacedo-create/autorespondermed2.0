<?php

namespace App\Filament\Resources\MerchantStores;

use App\Filament\Resources\MerchantStores\Pages\EditMerchantStore;
use App\Filament\Resources\MerchantStores\Pages\ListMerchantStores;
use App\Jobs\CaptureMerchantStore;
use App\Models\MerchantStore;
use App\Services\Stores\StoreCapturer;
use App\Support\Permissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * As lojas dos lojistas que tiveram MED: o site e as políticas capturadas. O
 * analista corrige a URL (ou a página de entrega, quando a busca não acha) e manda
 * capturar de novo.
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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Site da loja')
                ->description('A captura abre o site, acha as páginas de entrega, termos e trocas e guarda cada uma em PDF. Preencha a página específica só quando a busca não achar.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('Lojista')->disabled(),
                    TextInput::make('merchant_account_id')->label('Conta na plataforma')->disabled(),
                    TextInput::make('website')->label('Site')->url()->required()->maxLength(500)->columnSpanFull(),
                    TextInput::make('shipping_url')->label('Página de entrega')->url()->maxLength(500),
                    TextInput::make('exchange_url')->label('Página de trocas')->url()->maxLength(500),
                    TextInput::make('terms_url')->label('Termos de uso')->url()->maxLength(500),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('disputes'))
            ->columns([
                TextColumn::make('name')->label('Lojista')->description(fn (MerchantStore $record): string => 'conta '.$record->merchant_account_id)->searchable()->weight('medium'),
                TextColumn::make('website')->label('Site')->url(fn (MerchantStore $record): ?string => $record->website)->openUrlInNewTab()->placeholder('sem site')->limit(40)
                    ->description(fn (MerchantStore $record): ?string => match ($record->website_source) {
                        'unicopag_register' => 'pelo cadastro na plataforma',
                        'kyc' => 'pelo KYC',
                        'sale' => 'pela venda',
                        'manual' => 'preenchido no painel',
                        default => null,
                    }),
                TextColumn::make('capture_status')->label('Políticas')->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'ok' => 'Capturadas',
                        'partial' => 'Parcial',
                        'failed' => 'Falhou',
                        'no_website' => 'Sem site',
                        default => 'Pendente',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'ok' => 'success',
                        'partial' => 'warning',
                        'failed', 'no_website' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (MerchantStore $record): ?string => collect((array) $record->captures)->map(fn (array $c): string => MerchantStore::KINDS[$c['kind']] ?? $c['kind'])->implode(' · ') ?: $record->capture_error)
                    ->placeholder('Pendente')
                    ->wrap(),
                TextColumn::make('captured_at')->label('Capturado em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->placeholder('—')->sortable(),
                TextColumn::make('disputes_count')->label('MEDs')->badge()->color('gray')->sortable(),
            ])
            ->defaultSort('disputes_count', 'desc')
            ->recordActions([
                ...collect(MerchantStore::KINDS)->map(fn (string $label, string $kind): Action => Action::make("open_{$kind}")
                    ->label(match ($kind) {
                        'shipping' => 'Entrega',
                        'terms' => 'Termos',
                        default => 'Trocas',
                    })
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->url(fn (MerchantStore $record): string => route('admin.store.capture', [$record, $kind]))
                    ->openUrlInNewTab()
                    ->visible(fn (MerchantStore $record): bool => $record->capture($kind) !== null))->values()->all(),
                self::captureAction(),
                EditAction::make()->visible(fn (): bool => Permissions::allows(Permissions::REVIEW_DISPUTES)),
            ]);
    }

    public static function captureAction(): Action
    {
        return Action::make('capture')
            ->label('Capturar agora')
            ->icon('heroicon-o-camera')
            ->color('gray')
            ->visible(fn (MerchantStore $record): bool => Permissions::allows(Permissions::REVIEW_DISPUTES) && filled($record->website))
            ->action(function (MerchantStore $record, StoreCapturer $capturer): void {
                if (! $capturer->isAvailable()) {
                    Notification::make()->danger()->title('Captura indisponível')->body('O navegador não está instalado no servidor (doc/DEPLOY_FORGE.md).')->send();

                    return;
                }

                CaptureMerchantStore::dispatch($record->id);
                Notification::make()->success()->title('Captura pedida')->body('Leva até alguns minutos; os PDFs entram nos MEDs abertos desta loja.')->send();
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

    public static function canEdit($record): bool
    {
        return Permissions::allows(Permissions::REVIEW_DISPUTES);
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMerchantStores::route('/'),
            'edit' => EditMerchantStore::route('/{record}/edit'),
        ];
    }
}
