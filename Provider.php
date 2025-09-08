<?php

declare(strict_types=1);

namespace Tenancy\Database\Drivers\Postgresql;

use Tenancy\Database\Drivers\Postgresql\Listeners\ConfiguresTenantDatabase;
use Tenancy\Hooks\Database\Support\DatabaseProvider;

class Provider extends DatabaseProvider
{
    protected $listener = ConfiguresTenantDatabase::class;
}
