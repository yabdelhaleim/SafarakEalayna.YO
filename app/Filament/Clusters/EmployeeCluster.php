<?php

namespace App\Filament\Clusters;

use App\Filament\Resources\Employee\EmployeeResource;
use App\Filament\Resources\Employee\EmployeeAttendanceResource;
use App\Filament\Resources\Employee\EmployeeBonusResource;
use Filament\Clusters\Cluster;

class EmployeeCluster extends Cluster
{
    protected static ?string $title = 'إدارة الموظفين';

    protected static ?string $description = 'إدارة بيانات الموظفين والحضور والمكافآت';

    protected static ?int $navigationSort = 3;

    public static function getClusterItems(): array
    {
        return [
            EmployeeResource::class,
            EmployeeAttendanceResource::class,
            EmployeeBonusResource::class,
        ];
    }
}
