<?php

namespace App\Filament\Admin\Resources\FlightBookings\Pages;

use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\FlightBookings\FlightBookingResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditFlightBooking extends EditRecord
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = FlightBookingResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (empty($data['origin']) && ! empty($data['from_airport'])) {
            $data['origin'] = $data['from_airport'];
        }
        if (empty($data['destination']) && ! empty($data['to_airport'])) {
            $data['destination'] = $data['to_airport'];
        }
        if (empty($data['airline']) && ! empty($data['airline_name'])) {
            $data['airline'] = $data['airline_name'];
        }

        return $data;
    }

    /**
     * Defense-in-depth (DEFECT-011 / INCIDENT-2026-08-17): block Filament
     * edits on a FlightBooking even if the route or the form is reached.
     *
     * The Tourism no-edit contract says: a FlightBooking is immutable from
     * a financial standpoint after creation. Filament's default
     * handleRecordUpdate() runs $record->update($data), which would
     * silently mutate pricing / currency / customer_id / status without
     * posting any reversal entries to the GL.
     *
     * The supported correction path is: cancel (which posts reversal
     * entries) → create a new booking. There is no Edit page in the
     * contract. We throw LogicException here so that, if a stray link or
     * a stale URL is hit, the operation fails loudly instead of
     * corrupting the ledger.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        throw new \LogicException(
            'Tourism no-edit contract INCIDENT-2026-08-17: '
            .'FlightBooking edits via Filament are disabled. '
            .'To correct a booking, cancel it (which posts reversal '
            .'entries) and create a new one.'
        );
    }
}
