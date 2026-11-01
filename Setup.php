<?php

declare(strict_types=1);

namespace Repli\Seeder;

use App\Domain\Addon\AbstractSetup;
use App\Domain\Addon\StepResult;
use App\Domain\Addon\StepRunnerInstallTrait;
use App\Domain\Addon\StepRunnerUninstallTrait;
use App\Domain\Addon\StepRunnerUpgradeTrait;

final class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    /** Tabla que registra lo generado, para poder purgarlo despues. */
    public function installStep1(array $stepParams = []): ?StepResult
    {
        $this->query("
            CREATE TABLE IF NOT EXISTS `repli_seeder_items` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `batch`       VARCHAR(32)  NOT NULL,
                `entity_type` VARCHAR(16)  NOT NULL,
                `entity_id`   INT UNSIGNED NOT NULL,
                `extra_id`    INT UNSIGNED NULL DEFAULT NULL,
                `created_at`  INT UNSIGNED NOT NULL,
                INDEX `idx_batch` (`batch`),
                INDEX `idx_type` (`entity_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        return null;
    }

    public function installStep2(array $stepParams = []): ?StepResult
    {
        $this->seedAllPhrases();

        return null;
    }

    /** 1.0.4: frases traducibles (en/es); se vuelven a sembrar para que queden en la base de datos. */
    public function upgradeStep10004_1(array $stepParams = []): ?StepResult
    {
        $this->seedAllPhrases();

        return null;
    }

    /** 1.0.5: nombres de grupos traducibles; se vuelven a sembrar las frases. */
    public function upgradeStep10005_1(array $stepParams = []): ?StepResult
    {
        $this->seedAllPhrases();

        return null;
    }

    /** 1.0.6: frases en turco (tr.php); se vuelven a sembrar las frases. */
    public function upgradeStep10006_1(array $stepParams = []): ?StepResult
    {
        $this->seedAllPhrases();

        return null;
    }

    public function uninstallStep1(array $stepParams = []): ?StepResult
    {
        $this->deletePhrases();
        // Se conserva la tabla de registro: asi se puede purgar lo generado
        // reinstalando el addon. Eliminala a mano si ya no la necesitas.

        return null;
    }
}
