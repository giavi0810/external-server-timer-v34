<?php

namespace App\Database\Connectors;

use Illuminate\Database\Connectors\PostgresConnector;

class DashboardPostgresConnector extends PostgresConnector
{
    /**
     * Open the dashboard connection once so its timeout is a hard upper bound.
     */
    public function createConnection($dsn, array $config, array $options)
    {
        return $this->createPdoConnection(
            $dsn,
            $config['username'] ?? null,
            $config['password'] ?? null,
            $options,
        );
    }

    /**
     * Build a PostgreSQL DSN with a dashboard-only connection timeout.
     */
    protected function getDsn(array $config)
    {
        $dsn = parent::getDsn($config);
        $timeout = max(1, (int) ($config['connect_timeout'] ?? 3));

        return $dsn.";connect_timeout={$timeout}";
    }
}
