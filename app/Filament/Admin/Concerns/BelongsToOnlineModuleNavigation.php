<?php

namespace App\Filament\Admin\Concerns;

use App\Filament\Admin\Support\OnlineModuleNavigation;

trait BelongsToOnlineModuleNavigation
{
    public static function getNavigationParentItem(): ?string
    {
        return OnlineModuleNavigation::PARENT_LABEL;
    }
}
