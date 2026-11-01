<?php

declare(strict_types=1);

use App\System\Router;
use Repli\Seeder\Controller\SeederController;

// Rutas /admin/*: el core exige sesion de administrador por el prefijo.
return static function (Router $router): void {
    $ctrl = SeederController::class;

    $router->get('/admin/repli-seeder', [$ctrl, 'index'])->name('repli.seeder.index');
    $router->get('/admin/repli-seeder/status', [$ctrl, 'status'])->name('repli.seeder.status');
    $router->get('/admin/repli-seeder/estimate', [$ctrl, 'estimate'])->name('repli.seeder.estimate');

    $router->post('/admin/repli-seeder/run', [$ctrl, 'run'])->name('repli.seeder.run');
    $router->post('/admin/repli-seeder/cancel', [$ctrl, 'cancel'])->name('repli.seeder.cancel');
    $router->post('/admin/repli-seeder/purge', [$ctrl, 'purge'])->name('repli.seeder.purge');

    // Visitar por GET las URLs de accion muestra la misma pagina (con el avance si hay una carga en curso).
    $router->get('/admin/repli-seeder/run', [$ctrl, 'index'])->name('repli.seeder.run.page');
    $router->get('/admin/repli-seeder/cancel', [$ctrl, 'index'])->name('repli.seeder.cancel.page');
    $router->get('/admin/repli-seeder/purge', [$ctrl, 'index'])->name('repli.seeder.purge.page');
};
