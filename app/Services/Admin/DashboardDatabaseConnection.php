<?php

namespace App\Services\Admin;

use App\Database\Connectors\DashboardPostgresConnector;
use Illuminate\Support\Facades\DB;

class DashboardDatabaseConnection
{
    public function __construct(
        private readonly DashboardPostgresConnector $postgresConnector,
    ) {}

    /**
     * Establish the database connection used by the current dashboard request.
     */
    public function prepare(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            $connection->getPdo();

            return;
        }

        $config = $connection->getConfig();
        $config['connect_timeout'] = config('database.dashboard.connect_timeout', 3);

        $connection->setPdo($this->postgresConnector->connect($config));
    }
}
