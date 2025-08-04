<?php

declare(strict_types=1);

use Tempest\Database\Config\PostgresConfig;

use function Tempest\env;

return new PostgresConfig(
    host: env('POSTGRES_HOST', '127.0.0.1'),
    username: env('POSTGRES_USER', 'postgres'),
    password: env('POSTGRES_PASSWORD', '') ?? '',
    database: env('POSTGRES_DB', 'app'),
);
