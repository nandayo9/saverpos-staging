<?php

// Router for the disposable SAVERPOS demo only. `artisan serve` launches a
// child process that can lose shell-only feature-gate variables on this host;
// this router applies the explicitly documented local fixture configuration
// before Laravel reads it. It never contains production credentials.
$database = getenv('SAVERPOS_DEMO_DATABASE') ?: 'saverpos_demo_p0';
$socket = getenv('SAVERPOS_DEMO_SOCKET') ?: '/tmp/mysql.sock';
$user = getenv('SAVERPOS_DEMO_DB_USER') ?: 'root';

if (! preg_match('/^saverpos_demo_[a-z0-9_]+$/', $database)) {
    http_response_code(500);
    exit('Refusing a non-demo database name.');
}

putenv('DB_CONNECTION=mysql');
putenv('DB_HOST=localhost');
putenv('DB_PORT=3306');
putenv('DB_DATABASE='.$database);
putenv('DB_USERNAME='.$user);
putenv('DB_PASSWORD=');
putenv('DB_SOCKET='.$socket);
putenv('RECOMMERCE_ENABLED=(true)');
putenv('RECOMMERCE_WRITES_ENABLED=(true)');
putenv('RECOMMERCE_COHORT_BUSINESS_ID=1');
putenv('RECOMMERCE_COHORT_LOCATION_ID=1');
putenv('RECOMMERCE_COHORT_LOCATION_IDS=1,2');
putenv('RECOMMERCE_COHORT_VARIATION_IDS=1');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$publicPath = __DIR__.'/../public'.$path;
if (PHP_SAPI === 'cli-server' && is_file($publicPath)) {
    return false;
}

require __DIR__.'/../public/index.php';
