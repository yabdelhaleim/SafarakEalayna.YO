<?php

namespace App\Filament\Admin\Concerns;

use App\Filament\Admin\Support\FlightModuleNavigation;

trait BelongsToFlightModuleNavigation
{
    /**
     * لا نعرّف $navigationParentItem هنا لأن Filament يعرّفها في Resource/Page
     * وتسبب تعارضًا عند الدمج؛ نكتفي بتجاوز القارئ.
     */
    public static function getNavigationParentItem(): ?string
    {
        return FlightModuleNavigation::PARENT_LABEL;
    }
}
