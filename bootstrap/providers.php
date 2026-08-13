<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    // Telescope is a dev-only dependency — only register its provider when
    // the package is actually installed. Without this, fresh deploys with
    // `composer install --no-dev` fail at `php artisan package:discover`
    // because the parent class (TelescopeApplicationServiceProvider) is missing.
    ...(class_exists(\Laravel\Telescope\TelescopeApplicationServiceProvider::class)
        ? [App\Providers\TelescopeServiceProvider::class]
        : []),
];
