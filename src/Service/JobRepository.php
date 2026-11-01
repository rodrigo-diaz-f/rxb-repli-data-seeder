<?php

declare(strict_types=1);

namespace Repli\Seeder\Service;

use App\Infrastructure\Database\Database;

/**
 * Estado persistente de las generaciones (una fila por trabajo). La tabla se
 * crea sola la primera vez, asi no hace falta un upgrade del addon.
 */
final class JobRepository
{
    public const TABLE = 'repli_seeder_jobs';

    /** Estados en los que el trabajo sigue "vivo". */
    public const ACTIVE = ['queued', 'running', 'purging'];

    /** Segundos sin latido tras los cuales un trabajo activo se da por interrumpido. */
    public const STALE_SECONDS = 90;

    private const JSON_FIELDS = ['config', 'progress', 'errors', 'flood_saved'];
    private const WRITABLE = [
        'status', 'phase', 'progress', 'errors', 'flood_saved', 'cancel_requested',
        'purge_on_cancel', 'pid', 'started_at', 'heartbeat', 'finished_at',
    ];

    private static bool $ready = false;

    public function __construct(private readonly Database $db)
    {
    }

    public function ensureTable(): void
    {
        if (self::$ready) {
            return;
        }
        $this->db->execute('CREATE TABLE IF NOT EXISTS `' . self::TABLE . "` (
            `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `batch`            VARCHAR(16)  NOT NULL,
            `status`           VARCHAR(16)  NOT NULL DEFAULT 'queued',
            `phase`            VARCHAR(32)  NOT NULL DEFAULT '',
            `config`           MEDIUMTEXT   NOT NULL,
            `progress`         MEDIUMTEXT   NULL,
            `errors`           MEDIUMTEXT   NULL,
            `flood_saved`      TEXT         NULL,
            `cancel_requested` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `purge_on_cancel`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `pid`              INT UNSIGNED NULL DEFAULT NULL,
            `created_by`       INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at`       INT UNSIGNED NOT NULL,
            `started_at`       INT UNSIGNED NOT NULL DEFAULT 0,
            `heartbeat`        INT UNSIGNED NOT NULL DEFAULT 0,
            `finished_at`      INT UNSIGNED NULL DEFAULT NULL,
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::$ready = true;
    }

    public function create(string $batch, array $config, int $userId): int
    {
        $this->ensureTable();
        $now = time();
        $this->db->execute(
            'INSERT INTO `' . self::TABLE . '` (batch, status, config, created_by, created_at, heartbeat)
             VALUES (?, \'queued\', ?, ?, ?, ?)',
            [$batch, json_encode($config), $userId, $now, $now]
        );

        return (int) $this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $this->ensureTable();
        $row = $this->db->fetchOne('SELECT * FROM `' . self::TABLE . '` WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /** Ultimo trabajo aun vivo (sin evaluar si esta obsoleto). */
    public function active(): ?array
    {
        $this->ensureTable();
        $marks = implode(',', array_fill(0, count(self::ACTIVE), '?'));
        $row = $this->db->fetchOne(
            'SELECT * FROM `' . self::TABLE . "` WHERE status IN ({$marks}) ORDER BY id DESC LIMIT 1",
            self::ACTIVE
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function latest(): ?array
    {
        $this->ensureTable();
        $row = $this->db->fetchOne('SELECT * FROM `' . self::TABLE . '` ORDER BY id DESC LIMIT 1');

        return $row === null ? null : $this->hydrate($row);
    }

    /** @param array<string,mixed> $fields solo columnas permitidas */
    public function save(int $id, array $fields): void
    {
        $sets = [];
        $params = [];
        foreach ($fields as $column => $value) {
            if (!in_array($column, self::WRITABLE, true)) {
                continue;
            }
            $sets[] = "`{$column}` = ?";
            $params[] = in_array($column, self::JSON_FIELDS, true) && $value !== null
                ? json_encode($value, JSON_UNESCAPED_UNICODE)
                : $value;
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $this->db->execute('UPDATE `' . self::TABLE . '` SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    /** Borra el historial de generaciones terminadas (las activas no se tocan). */
    public function deleteFinished(): void
    {
        $this->ensureTable();
        $marks = implode(',', array_fill(0, count(self::ACTIVE), '?'));
        $this->db->execute('DELETE FROM `' . self::TABLE . "` WHERE status NOT IN ({$marks})", self::ACTIVE);
    }

    public function requestCancel(int $id, bool $purge): void
    {
        $this->save($id, ['cancel_requested' => 1, 'purge_on_cancel' => $purge ? 1 : 0]);
    }

    /** @return array{cancel:bool,purge:bool} */
    public function flags(int $id): array
    {
        $row = $this->db->fetchOne(
            'SELECT cancel_requested, purge_on_cancel FROM `' . self::TABLE . '` WHERE id = ?',
            [$id]
        );

        return [
            'cancel' => $row !== null && (int) $row['cancel_requested'] === 1,
            'purge'  => $row !== null && (int) $row['purge_on_cancel'] === 1,
        ];
    }

    private function hydrate(array $row): array
    {
        foreach (self::JSON_FIELDS as $field) {
            $raw = $row[$field] ?? null;
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $row[$field] = is_array($decoded) ? $decoded : ($field === 'flood_saved' ? null : []);
        }

        return $row;
    }
}
