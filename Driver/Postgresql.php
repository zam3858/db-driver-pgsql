<?php

declare(strict_types=1);

namespace Tenancy\Database\Drivers\Postgresql\Driver;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tenancy\Affects\Connections\Contracts\ResolvesConnections;
use Tenancy\Database\Drivers\Postgresql\Concerns\ManagesSystemConnection;
use Tenancy\Facades\Tenancy;
use Tenancy\Hooks\Database\Contracts\ProvidesDatabase;
use Tenancy\Hooks\Database\Events\Drivers as Events;
use Tenancy\Identification\Contracts\Tenant;

class Postgresql implements ProvidesDatabase
{
    public function configure(Tenant $tenant): array
    {
        $config = [];

        event(new Events\Configuring($tenant, $config, $this));

        return $config;
    }

    public function create(Tenant $tenant): bool
    {
        $config = $this->configure($tenant);

        event(new Events\Creating($tenant, $config, $this));

        return $this->processAndDispatch(Events\Created::class, $tenant, [
            'user'     => "DO $$ BEGIN IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '{$config['username']}') THEN CREATE ROLE \"{$config['username']}\" LOGIN PASSWORD '{$config['password']}'; END IF; END $$;",
            'database' => "CREATE DATABASE \"{$config['database']}\" OWNER \"{$config['username']}\";",
            'grant'    => "GRANT ALL PRIVILEGES ON DATABASE \"{$config['database']}\" TO \"{$config['username']}\";",
        ]);
    }

    public function update(Tenant $tenant): bool
    {
        $config = $this->configure($tenant);

        event(new Events\Updating($tenant, $config, $this));

        if (!isset($config['oldUsername'])) {
            return false;
        }

        $tableStatements = [];

        foreach ($this->retrieveTables($tenant) as $table) {
            $tableStatements['move-table-'.$table] = "ALTER TABLE \"{$config['oldUsername']}\".{$table} SET SCHEMA \"{$config['database']}\";";
        }

        $statements = array_merge([
            'user'     => "ALTER ROLE \"{$config['oldUsername']}\" RENAME TO \"{$config['username']}\";",
            'password' => "ALTER ROLE \"{$config['username']}\" WITH PASSWORD '{$config['password']}';",
            'database' => "CREATE DATABASE \"{$config['database']}\" OWNER \"{$config['username']}\";",
            'grant'    => "GRANT ALL PRIVILEGES ON DATABASE \"{$config['database']}\" TO \"{$config['username']}\";",
        ], $tableStatements);

        $statements['delete-db'] = "DROP DATABASE IF EXISTS \"{$config['oldUsername']}\";";

        return $this->processAndDispatch(Events\Updated::class, $tenant, $statements);
    }

    public function delete(Tenant $tenant): bool
    {
        $config = $this->configure($tenant);

        event(new Events\Deleting($tenant, $config, $this));

        return $this->processAndDispatch(Events\Deleted::class, $tenant, [
            'user'     => "DROP ROLE IF EXISTS \"{$config['username']}\";",
            'database' => "DROP DATABASE IF EXISTS \"{$config['database']}\";",
        ]);
    }

    protected function system(Tenant $tenant): ConnectionInterface
    {
        $connection = null;

        if (in_array(ManagesSystemConnection::class, class_implements($tenant))) {
            $connection = $tenant->getManagingSystemConnection() ?? $connection;
        }

        return DB::connection($connection);
    }

    protected function process(Tenant $tenant, array $statements): bool
    {
        $success = false;

        $this->system($tenant)->beginTransaction();

        foreach ($statements as $statement) {
            try {
                $success = $this->system($tenant)->statement($statement);
            } catch (QueryException $e) {
                $this->system($tenant)->rollBack();
            } finally {
                if (!$success) {
                    throw $e;
                }
            }
        }

        $this->system($tenant)->commit();

        return $success;
    }

    protected function retrieveTables(Tenant $tenant): array
    {
        $tempTenant = $tenant->replicate();
        $tempTenant->{$tenant->getTenantKeyName()} = $tenant->getOriginal($tenant->getTenantKeyName());

        /** @var ResolvesConnections $resolver */
        $resolver = resolve(ResolvesConnections::class);
        $resolver($tempTenant, Tenancy::getTenantConnectionName());

        $tables = Tenancy::getTenantConnection()->getDoctrineSchemaManager()->listTableNames();

        $resolver(null, Tenancy::getTenantConnectionName());

        return $tables;
    }

    private function processAndDispatch(string $event, Tenant $tenant, array $statements)
    {
        $result = $this->process($tenant, $statements);

        event((new $event($tenant, $this, $result)));

        return $result;
    }
}
