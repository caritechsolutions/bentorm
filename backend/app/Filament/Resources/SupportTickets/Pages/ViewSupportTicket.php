<?php

namespace App\Filament\Resources\SupportTickets\Pages;

use App\Filament\Resources\SupportTickets\SupportTicketResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSupportTicket extends ViewRecord
{
    protected static string $resource = SupportTicketResource::class;

    public function getTitle(): string
    {
        return 'Ticket '.$this->record->number();
    }

    protected function getHeaderActions(): array
    {
        return SupportTicketResource::actions();
    }
}
