<?php

namespace App\Filament\Admin\Widgets;

use Filament\Widgets\Widget;

class AdminPortalWidget extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.admin.widgets.admin-portal-widget';
}
