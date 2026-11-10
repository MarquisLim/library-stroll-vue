<?php

require dirname(__DIR__).'/vendor/autoload.php';

// Container variables can remain in $_SERVER even when PHPUnit forces <env>.
// Set every environment adapter before Laravel boots or RefreshDatabase runs.
if (is_file(dirname(__DIR__).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Tests require uncached configuration. Refusing to use a possibly live database.');
}

foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('0', 32)),
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'BROADCAST_CONNECTION' => 'log',
    'MAIL_MAILER' => 'array',
    'AI_SERVICE_ENABLED' => 'false',
    'LOG_CHANNEL' => 'null',
] as $name => $value) {
    putenv("$name=$value");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
