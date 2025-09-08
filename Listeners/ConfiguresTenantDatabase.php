<?php

declare(strict_types=1);

namespace Tenancy\Database\Drivers\Postgresql\Listeners;

use Tenancy\Database\Drivers\Postgresql\Driver\Postgresql;
use Tenancy\Hooks\Database\Contracts\ProvidesDatabase;
use Tenancy\Hooks\Database\Events\Resolving;

class ConfiguresTenantDatabase
{
    public function handle(Resolving $resolving): ?ProvidesDatabase
    {
        return new Postgresql();
    }
}
