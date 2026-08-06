<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\ModuleServiceProvider;

return [
    AppServiceProvider::class,
    // Discovers app/Modules/*/Providers/*ServiceProvider.php — new modules need no
    // edit to this file. See app/Providers/ModuleServiceProvider.php.
    ModuleServiceProvider::class,
];
