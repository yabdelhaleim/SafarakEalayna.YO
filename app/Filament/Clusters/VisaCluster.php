<?php

namespace App\Filament\Clusters;

use App\Filament\Admin\Resources\VisaAgents\VisaAgentResource;
use App\Filament\Admin\Resources\VisaBankAccounts\VisaBankAccountResource;
use App\Filament\Admin\Resources\VisaBookings\VisaBookingResource;
use App\Filament\Admin\Resources\VisaDurations\VisaDurationResource;
use App\Filament\Admin\Resources\VisaWallets\VisaWalletResource;
use Filament\Clusters\Cluster;

class VisaCluster extends Cluster
{
    protected static ?string $title = 'التأشيرات';

    protected static ?string $description = 'إدارة حجوزات وخدمات التأشيرات';

    protected static ?int $navigationSort = 2;

    protected static ?string $icon = 'heroicon-o-identification';

    public static function getClusterItems(): array
    {
        return [
            VisaBookingResource::class,
            VisaAgentResource::class,
            VisaBankAccountResource::class,
            // VisaTreasuryResource removed in Phase 4 STEP 2 — its data is
            // visible from the general AccountResource instead.
            VisaWalletResource::class,
            VisaDurationResource::class,
        ];
    }
}
