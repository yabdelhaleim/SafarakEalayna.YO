<?php

namespace App\Filament\Admin\Concerns;

use App\Filament\Admin\Support\HajjUmraModuleNavigation;

trait BelongsToHajjUmraModuleNavigation
{
    public static function getNavigationParentItem(): ?string
    {
        return HajjUmraModuleNavigation::PARENT_LABEL;
    }
}

