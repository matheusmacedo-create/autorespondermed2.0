<?php

namespace App\Filament\Resources\Disputes\Pages;

use App\Filament\Resources\Disputes\DisputeResource;
use App\Filament\Resources\Disputes\Support\DisputeActions;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;

/**
 * A ficha do caso. "Enviar à Woovi" é a única ação colorida: é a decisão.
 */
class ViewDispute extends ViewRecord
{
    protected static string $resource = DisputeResource::class;

    public function getTitle(): string
    {
        $dispute = $this->getRecord();

        return "{$dispute->type} {$dispute->formattedValue()} - {$dispute->wooviAccount->name}";
    }

    protected function getHeaderActions(): array
    {
        return [
            DisputeActions::sendToWoovi(),
            DisputeActions::requestComplement(),
            ActionGroup::make([
                DisputeActions::editAnalystNotes(),
                DisputeActions::attachEvidence(),
                DisputeActions::resendSellerNotice(),
                DisputeActions::rebuildDossier(),
                DisputeActions::syncWithWoovi(),
            ])->label('Mais')->icon('heroicon-o-ellipsis-horizontal')->button()->color('gray'),
        ];
    }
}
