<?php

declare(strict_types=1);

namespace Repli\Seeder;

use App\Domain\Addon\AbstractAddonServiceProvider;

final class ServiceProvider extends AbstractAddonServiceProvider
{
    public function register(): void
    {
        // Los servicios del addon se resuelven por autowiring (reflexion).
    }

    public function boot(): void
    {
        // Registra la ruta de plantillas del addon.
        parent::boot();

        $this->loadRoutes('routes/admin.php');

        $this->registerAdminNav('Data Seeder', '/admin/repli-seeder', 'fas fa-seedling', 900);
    }
}
