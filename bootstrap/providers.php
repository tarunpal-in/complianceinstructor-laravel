<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,
    // Must come after the framework's RoutingServiceProvider so our url binding
    // is the last word; app providers always do.
    App\Providers\UrlServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\Filament\AccountPanelProvider::class,
];
