<?php

namespace App\Filament\Admin\Concerns;

use App\Filament\Admin\Support\FawryModuleNavigation;

trait BelongsToFawryModuleNavigation
{
    public static function getNavigationParentItem(): ?string
    {
        return FawryModuleNavigation::PARENT_LABEL;
    }
}
