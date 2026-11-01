<?php

declare(strict_types=1);

/**
 * Proceso en segundo plano del Repli Seeder. Lo lanza el panel de admin:
 *   php addons/Repli/Seeder/bin/worker.php <jobId>
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$jobId = (int) ($argv[1] ?? 0);
if ($jobId <= 0) {
    fwrite(STDERR, "Usage: worker.php <jobId>\n");
    exit(2);
}

$root = dirname(__DIR__, 4);
define('APP_ROOT', $root);
chdir($root);

require $root . '/src/bootstrap.php';

\App\System\SessionManager::markRequestTerminated();
if (method_exists(\App\Infrastructure\Database\Database::class, 'setExecContext')) {
    \App\Infrastructure\Database\Database::setExecContext('cron');
}
@ignore_user_abort(true);
@set_time_limit(0);

$app = new \App\System\Application($root);
(new \App\System\Kernel($app))->bootConsole();

$service = $app->getContainer()->resolve(\Repli\Seeder\Service\SeederService::class);

// Si el proceso muere por un error fatal, el trabajo no debe quedar "en curso".
register_shutdown_function(static function () use ($service, $jobId): void {
    $service->failIfUnfinished($jobId);
});

$service->runWorker($jobId);
exit(0);
