<?php

namespace App\Filament\Admin\Resources\ApprovalWorkflows\Pages;

use App\Filament\Admin\Resources\ApprovalWorkflows\ApprovalWorkflowResource;
use Filament\Resources\Pages\ManageRecords;

class ManageApprovalWorkflows extends ManageRecords
{
    protected static string $resource = ApprovalWorkflowResource::class;
}