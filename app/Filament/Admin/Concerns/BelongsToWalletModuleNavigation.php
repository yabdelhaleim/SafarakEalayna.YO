<?php

namespace App\Filament\Admin\Concerns;

use App\Filament\Admin\Support\WalletModuleNavigation;

trait BelongsToWalletModuleNavigation
{
    public static function getNavigationParentItem(): ?string
    {
        return WalletModuleNavigation::PARENT_LABEL;
    }
}
