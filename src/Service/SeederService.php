<?php

declare(strict_types=1);

namespace Repli\Seeder\Service;

use App\Domain\Admin\Service\OptionsService;
use App\Domain\Moderation\Service\ThreadModerationService;
use App\Domain\Node\DTO\CreateNodeDTO;
use App\Domain\Node\DTO\DeleteNodeDTO;
use App\Domain\Node\Service\NodeManagerService;
use App\Domain\Post\DTO\CreatePostDTO;
use App\Domain\Post\Service\PostReplyService;
use App\Domain\Reaction\DTO\ToggleReactionDTO;
use App\Domain\Reaction\Repository\ReactionDefinitionRepository;
use App\Domain\Reaction\Service\ReactionService;
use App\Domain\Thread\DTO\CreateThreadDTO;
use App\Domain\Thread\Service\ThreadCreatorService;
use App\Domain\User\DTO\RegisterUserDTO;
use App\Domain\User\DTO\UserDeleteDTO;
use App\Domain\User\Service\UserDeleteService;
use App\Domain\User\Service\UserRegistrationService;
use App\Domain\UserGroup\DTO\CreateUserGroupDTO;
use App\Domain\UserGroup\Service\UserGroupManagerService;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Database\ForumModeratorRepository;
use App\Infrastructure\Database\UserGroupRepository;
use App\Infrastructure\Database\UserRepository;

/**
 * Genera y purga datos de prueba usando los servicios del core, de modo que
 * contadores, indices de busqueda y eventos queden coherentes.
 *
 * La generacion corre en un proceso PHP aparte (bin/worker.php) lanzado desde el
 * panel; el estado y el avance viven en la tabla repli_seeder_jobs.
 */
final class SeederService
{
    private const TABLE = 'repli_seeder_items';

    /** Si la estimacion supera este tiempo, el panel pide una confirmacion adicional. */
    public const WARN_SECONDS = 60;

    /** Orden de la purga: reacciones, hilos (con respuestas), luego el resto. */
    private const PURGE_ORDER = ['reaction', 'thread', 'moderator', 'user', 'node', 'group'];

    /** Costo inicial aproximado por entidad, en ms. Son supuestos; la estimacion real no esta medida. */
    private const UNIT_MS = [
        'group' => 40, 'user' => 250, 'user_group' => 3, 'node' => 30, 'moderator' => 5,
        'mod_assign' => 5, 'thread' => 90, 'reply' => 60, 'reaction' => 30,
    ];

    private const BAR_COST = [
        'groups' => 'group', 'users' => 'user', 'user_groups' => 'user_group', 'categories' => 'node',
        'forums' => 'node', 'moderators' => 'moderator', 'mod_assign' => 'mod_assign', 'threads' => 'thread',
        'replies' => 'reply', 'thread_reactions' => 'reaction', 'reply_reactions' => 'reaction',
    ];

    private const BAR_LABELS = [
        'groups' => 'User groups', 'users' => 'Users', 'user_groups' => 'Group memberships',
        'categories' => 'Categories', 'forums' => 'Forums and subforums', 'moderators' => 'Moderators',
        'mod_assign' => 'Moderator assignments', 'threads' => 'Threads', 'replies' => 'Replies',
        'thread_reactions' => 'Thread reactions', 'reply_reactions' => 'Reply reactions',
    ];

    /**
     * Diseños de banner predefinidos del editor de grupos del core (clase userBanner--<diseño>).
     * Se omiten red y green: son los de Administradores y Moderadores.
     */
    private const BANNER_PRESETS = [
        'blue', 'royalBlue', 'skyBlue', 'orange', 'yellow', 'steelBlue', 'navyBlue', 'deepTeal',
        'tealSolid', 'amber', 'olive', 'lightGreen', 'crimsonOutline', 'violetOutline', 'aquaOutline',
        'orangeOutline', 'emeraldStripe', 'tealStripe', 'primary', 'accent', 'gray', 'silver',
        'charcoal', 'crimsonSolid', 'violetSolid',
    ];

    /** Iconos Font Awesome que ofrece el editor de grupos del core. */
    private const BANNER_ICONS = [
        'fa-award', 'fa-shield-halved', 'fa-star', 'fa-crown', 'fa-gem', 'fa-certificate',
        'fa-trophy', 'fa-medal', 'fa-bolt', 'fa-fire', 'fa-heart', 'fa-hammer', 'fa-gavel',
    ];

    private const TOPICS = [
        'Technology', 'Gaming', 'Music', 'Sports', 'Cooking', 'Travel', 'Movies', 'Books',
        'Science', 'Photography', 'Fitness', 'Cars', 'Gardening', 'Art', 'Finance', 'Pets',
    ];

    private const WORDS = [
        'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'elit', 'sed', 'do',
        'eiusmod', 'tempor', 'incididunt', 'labore', 'magna', 'aliqua', 'enim', 'minim', 'veniam',
        'quis', 'nostrud', 'exercitation', 'ullamco', 'laboris', 'nisi', 'aliquip', 'commodo',
        'consequat', 'duis', 'aute', 'irure', 'reprehenderit', 'voluptate', 'velit', 'esse',
        'cillum', 'fugiat', 'nulla', 'pariatur', 'excepteur', 'sint', 'occaecat', 'cupidatat',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly OptionsService $options,
        private readonly NodeManagerService $nodes,
        private readonly UserGroupManagerService $groups,
        private readonly UserGroupRepository $groupRepo,
        private readonly UserRepository $userRepo,
        private readonly UserRegistrationService $registration,
        private readonly UserDeleteService $userDelete,
        private readonly ForumModeratorRepository $moderators,
        private readonly ThreadCreatorService $threadCreator,
        private readonly PostReplyService $postReply,
        private readonly ReactionService $reactions,
        private readonly ReactionDefinitionRepository $reactionDefs,
        private readonly ThreadModerationService $threadModeration,
        private readonly JobRepository $jobs,
    ) {
    }

    // ------------------------------------------------------------- formulario

    /** Valores por defecto del formulario. */
    public static function defaults(): array
    {
        return [
            'categories'              => 3,
            'forums_per_category'     => 3,
            'subforums_per_forum'     => 1,
            'groups'                  => 3,
            'users'                   => 20,
            'moderators'              => 3,
            'threads_per_forum'       => 5,
            'max_replies'             => 6,
            'enable_thread_reactions' => 0,
            'thread_reaction_pct'     => 50,
            'enable_reply_reactions'  => 0,
            'reply_reaction_pct'      => 50,
            'username_prefix'         => 'seed',
            'password'                => 'SeedPass#2026',
            'rng_seed'                => 0,
        ];
    }

    /** Resumen de lo generado hasta ahora. */
    public function stats(): array
    {
        $out = ['node' => 0, 'user' => 0, 'group' => 0, 'moderator' => 0, 'thread' => 0, 'reaction' => 0];
        try {
            $rows = $this->db->fetchAll(
                'SELECT entity_type, COUNT(*) AS c FROM ' . self::TABLE . ' GROUP BY entity_type'
            );
            foreach ($rows as $row) {
                $out[(string) $row['entity_type']] = (int) $row['c'];
            }
        } catch (\Throwable) {
            // La tabla aun no existe (addon sin instalar).
        }

        return $out;
    }

    /**
     * Cantidades y tiempo aproximado de una configuracion. Las respuestas, las
     * asignaciones y las pertenencias a grupos son valores esperados (promedio).
     *
     * @return array{counts:array<string,int>,rows:list<array{key:string,label:string,total:int}>,entities:int,seconds:int,limit:int}
     */
    public function estimate(array $input): array
    {
        $cfg = $this->normalize($input);
        $forums = $cfg['categories'] * $cfg['forums_per_category'] * (1 + $cfg['subforums_per_forum']);
        $threads = $forums * $cfg['threads_per_forum'];
        $replies = (int) round($threads * $cfg['max_replies'] / 2);
        $staff = $cfg['moderators'] > 0 && $cfg['users'] > 0 && $forums > 0;
        $mods = $staff ? min($cfg['moderators'], $cfg['users']) : 0;

        $counts = [
            'groups'           => $cfg['groups'] + ($staff ? 1 : 0),
            'users'            => $cfg['users'],
            'user_groups'      => (int) round($cfg['users'] * min(2, $cfg['groups']) / 2),
            'categories'       => $cfg['categories'],
            'forums'           => $forums,
            'moderators'       => $mods,
            'mod_assign'       => (int) round($mods * (1 + min(2, max(1, $forums))) / 2),
            'threads'          => $threads,
            'replies'          => $replies,
            'thread_reactions' => $this->reactionTarget($cfg['enable_thread_reactions'], $cfg['thread_reaction_pct'], $threads),
            'reply_reactions'  => $this->reactionTarget($cfg['enable_reply_reactions'], $cfg['reply_reaction_pct'], $replies),
        ];

        $ms = 0.0;
        $rows = [];
        $entities = 0;
        foreach ($counts as $key => $total) {
            $ms += $total * self::UNIT_MS[self::BAR_COST[$key]];
            if ($total > 0) {
                $rows[] = ['key' => $key, 'label' => self::BAR_LABELS[$key], 'total' => $total];
                $entities += $total;
            }
        }

        return [
            'counts'   => $counts,
            'rows'     => $rows,
            'entities' => $entities,
            'seconds'  => (int) round($ms / 1000),
            'limit'    => self::WARN_SECONDS,
        ];
    }

    public static function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes . ' min ' . ($seconds % 60) . ' s';
        }

        return intdiv($minutes, 60) . ' h ' . ($minutes % 60) . ' min';
    }

    // ---------------------------------------------------------------- trabajos

    /**
     * @param array<string,string> $labels nombres de los grupos ya traducidos al idioma del admin
     *                                     (group, staff, staff_banner)
     * @return array{job?:int,error?:string} error: 'busy' | 'spawn'
     */
    public function startJob(array $input, int $userId, array $labels = []): array
    {
        $this->jobs->ensureTable();
        if ($this->activeJob() !== null) {
            return ['error' => 'busy'];
        }

        $cfg = $this->normalize($input);
        $cfg['labels'] = array_map(static fn ($v): string => mb_substr(trim((string) $v), 0, 40), $labels);
        $jobId = $this->jobs->create(date('mdHis'), $cfg, $userId);

        $pid = $this->spawnWorker($jobId);
        if ($pid === null) {
            $this->jobs->save($jobId, [
                'status'      => 'failed',
                'phase'       => 'spawn_failed',
                'errors'      => ['@@spawn'],
                'finished_at' => time(),
            ]);

            return ['error' => 'spawn'];
        }
        $this->jobs->save($jobId, ['pid' => $pid]);

        return ['job' => $jobId];
    }

    /** Trabajo vivo; si dejo de dar senales de vida se marca como interrumpido. */
    public function activeJob(): ?array
    {
        $job = $this->jobs->active();
        if ($job === null) {
            return null;
        }
        if (time() - (int) $job['heartbeat'] > JobRepository::STALE_SECONDS) {
            $this->abandon($job);

            return null;
        }

        return $job;
    }

    /**
     * Ultima generacion, solo mientras siga siendo relevante: si sus datos ya no
     * existen (purgados) no se muestra. Las fallidas se muestran siempre, por sus errores.
     */
    public function lastJobToShow(): ?array
    {
        $job = $this->jobs->latest();
        if ($job === null || in_array($job['status'], JobRepository::ACTIVE, true)) {
            return $job;
        }
        if ($job['status'] === 'failed') {
            return $job;
        }

        try {
            $remaining = (int) $this->db->fetchColumn(
                'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE batch = ?',
                [(string) $job['batch']]
            );
        } catch (\Throwable) {
            $remaining = 0;
        }

        return $remaining > 0 ? $job : null;
    }

    /** Para el sondeo de estado: el trabajo vivo o, si no hay, el ultimo registrado. */
    public function latestJob(): ?array
    {
        return $this->jobs->latest();
    }

    public function requestCancel(bool $purge): bool
    {
        $job = $this->activeJob();
        if ($job === null) {
            return false;
        }
        $this->jobs->requestCancel((int) $job['id'], $purge);

        return true;
    }

    /** Datos para la pagina de avance (y su JSON). */
    public function statusPayload(array $job): array
    {
        $progress = (array) ($job['progress'] ?? []);
        $bars = (array) ($progress['bars'] ?? []);
        $meta = (array) ($progress['meta'] ?? []);
        $active = in_array($job['status'], JobRepository::ACTIVE, true);

        $status = (string) $job['status'];
        if ($active && !empty($job['cancel_requested']) && $status !== 'purging') {
            $status = 'cancelling';
        }

        $now = time();
        $started = (int) $job['started_at'];
        $end = $active ? $now : (int) ($job['finished_at'] ?? $now);
        $elapsed = $started > 0 ? max(0, $end - $started) : 0;

        // Tiempo restante: se escala el costo estimado con la velocidad real medida.
        $eta = null;
        if ($job['status'] === 'running' && $elapsed >= 3) {
            $doneWork = 0.0;
            $totalWork = 0.0;
            foreach ($bars as $bar) {
                $cost = self::UNIT_MS[self::BAR_COST[$bar['key']] ?? 'thread'] ?? 50;
                $doneWork += ((int) $bar['done'] + (int) $bar['failed']) * $cost;
                $totalWork += (int) $bar['total'] * $cost;
            }
            if ($doneWork > 0) {
                $eta = (int) round(max(0.0, $totalWork - $doneWork) / ($doneWork / $elapsed));
            }
        }

        return [
            'id'       => (int) $job['id'],
            'batch'    => (string) $job['batch'],
            'status'   => $status,
            'phase'    => (string) $job['phase'],
            'finished' => !$active,
            'elapsed'  => $elapsed,
            'eta'      => $eta,
            'bars'     => $bars,
            'errors'   => array_slice((array) $job['errors'], 0, 15),
            'rng_seed' => $meta['rng_seed'] ?? null,
        ];
    }

    /** Punto de entrada del proceso en segundo plano. */
    public function runWorker(int $jobId): void
    {
        try {
            $this->runJob($jobId);
        } catch (\Throwable $e) {
            $this->failJob($jobId, $e->getMessage());
        }
    }

    /** Red de seguridad para el cierre del proceso: marca como fallido lo que quedo a medias. */
    public function failIfUnfinished(int $jobId): void
    {
        $job = $this->jobs->find($jobId);
        if ($job !== null && in_array($job['status'], JobRepository::ACTIVE, true)) {
            $this->failJob($jobId, '@@ended');
        }
    }

    // ------------------------------------------------------------------ purga

    /**
     * Elimina lo generado (todo, o solo un lote). Antes de borrar los foros se
     * barre TODO el contenido que haya dentro (de cualquier autor): primero sus
     * reacciones y luego los hilos con sus respuestas. Es de mejor esfuerzo: lo
     * que falle se conserva en la tabla de seguimiento para reintentar.
     *
     * @return array{deleted:int,errors:list<string>}
     */
    public function purge(int $actorId = 0, ?string $batch = null, ?callable $tick = null): array
    {
        @set_time_limit(0);

        $deleted = 0;
        $errors = [];

        foreach (self::PURGE_ORDER as $type) {
            if ($type === 'thread') {
                $deleted += $this->sweepForumThreads($actorId, $errors, $batch, $tick);
            }

            $sql = 'SELECT id, entity_id, extra_id FROM ' . self::TABLE . ' WHERE entity_type = ?';
            $params = [$type];
            if ($batch !== null) {
                $sql .= ' AND batch = ?';
                $params[] = $batch;
            }
            $rows = $this->db->fetchAll($sql . ' ORDER BY id DESC', $params);

            foreach ($rows as $row) {
                if ($tick !== null) {
                    $tick();
                }
                $entityId = (int) $row['entity_id'];
                try {
                    match ($type) {
                        // Se quita con el servicio del core: ajusta puntajes y dispara eventos.
                        'reaction'  => $this->removeReaction($entityId),
                        // Hilos registrados que sigan existiendo (p. ej. movidos a otro foro).
                        'thread'    => $this->deleteThreadCompletely($entityId, $actorId, $errors),
                        'moderator' => $this->moderators->remove($entityId, (int) $row['extra_id']),
                        // Borra tambien el contenido que quede del usuario sembrado.
                        'user'      => $this->userDelete->delete(new UserDeleteDTO($entityId, UserDeleteDTO::ACTION_DELETE)),
                        'node'      => $this->nodes->deleteNode(new DeleteNodeDTO($entityId)),
                        'group'     => $this->groups->delete($entityId),
                    };
                    $this->db->execute('DELETE FROM ' . self::TABLE . ' WHERE id = ?', [(int) $row['id']]);
                    $deleted++;
                } catch (\Throwable $e) {
                    $errors[] = "{$type} #{$entityId}: " . $e->getMessage();
                }
            }
        }

        // Purga global completa: el historial de generaciones ya no describe nada que exista.
        if ($batch === null && $errors === []) {
            $this->jobs->deleteFinished();
        }

        return ['deleted' => $deleted, 'errors' => array_slice($errors, 0, 15)];
    }

    /**
     * Borra todos los hilos de los foros generados, sean de quien sean.
     *
     * @return int hilos eliminados
     */
    private function sweepForumThreads(int $actorId, array &$errors, ?string $batch, ?callable $tick): int
    {
        $sql = 'SELECT entity_id FROM ' . self::TABLE . " WHERE entity_type = 'node'";
        $params = [];
        if ($batch !== null) {
            $sql .= ' AND batch = ?';
            $params[] = $batch;
        }
        $nodeIds = array_map(static fn (array $r): int => (int) $r['entity_id'], $this->db->fetchAll($sql, $params));

        $count = 0;
        foreach (array_chunk($nodeIds, 200) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($this->db->fetchAll("SELECT id FROM threads WHERE node_id IN ({$marks})", $chunk) as $row) {
                if ($tick !== null) {
                    $tick();
                }
                try {
                    if ($this->deleteThreadCompletely((int) $row['id'], $actorId, $errors)) {
                        $count++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = "thread #{$row['id']}: " . $e->getMessage();
                }
            }
        }

        return $count;
    }

    /**
     * Quita primero las reacciones del hilo y de sus respuestas y despues borra
     * el hilo con todos sus mensajes.
     *
     * @return bool true si el hilo existia y se elimino
     */
    private function deleteThreadCompletely(int $threadId, int $actorId, array &$errors): bool
    {
        $exists = (int) $this->db->fetchColumn('SELECT COUNT(*) FROM threads WHERE id = ?', [$threadId]) > 0;
        if (!$exists) {
            return false;
        }

        $reactions = $this->db->fetchAll(
            "SELECT content_type, content_id, user_id, reaction_id FROM reactions
              WHERE (content_type = 'thread' AND content_id = ?)
                 OR (content_type = 'post' AND content_id IN (SELECT id FROM posts WHERE thread_id = ?))",
            [$threadId, $threadId]
        );
        foreach ($reactions as $r) {
            try {
                // Alternar con la misma reaccion la quita y ajusta puntajes.
                $this->reactions->toggle(new ToggleReactionDTO(
                    (string) $r['content_type'],
                    (int) $r['content_id'],
                    (int) $r['user_id'],
                    (int) $r['reaction_id']
                ));
            } catch (\Throwable $e) {
                $errors[] = "reaction on {$r['content_type']} #{$r['content_id']}: " . $e->getMessage();
            }
        }
        // El borrado de hilos del core no limpia las reacciones del propio hilo.
        $this->db->execute("DELETE FROM reactions WHERE content_type = 'thread' AND content_id = ?", [$threadId]);

        $this->threadModeration->hardDelete($threadId, $actorId, 'Repli Seeder purge');

        return true;
    }

    /** Quita una reaccion registrada usando el servicio del core. */
    private function removeReaction(int $reactionRowId): void
    {
        $row = $this->db->fetchOne(
            'SELECT content_type, content_id, user_id, reaction_id FROM reactions WHERE id = ?',
            [$reactionRowId]
        );
        if ($row === null) {
            return; // ya no existe (por ejemplo, se borro el contenido)
        }

        $res = $this->reactions->toggle(new ToggleReactionDTO(
            (string) $row['content_type'],
            (int) $row['content_id'],
            (int) $row['user_id'],
            (int) $row['reaction_id']
        ));
        if (($res['action'] ?? '') !== 'removed') {
            throw new \RuntimeException('could not remove the reaction (' . (string) ($res['action'] ?? 'error') . ')');
        }
    }

    // --------------------------------------------------------- proceso de fondo

    private function runJob(int $jobId): void
    {
        $job = $this->jobs->find($jobId);
        if ($job === null || $job['status'] !== 'queued') {
            return;
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '1G');

        $cfg = (array) $job['config'];
        $batch = (string) $job['batch'];
        $actor = (int) $job['created_by'];
        $p = new JobProgress($this->jobs, $jobId);

        $this->jobs->save($jobId, ['status' => 'running', 'phase' => 'planning', 'started_at' => time(), 'heartbeat' => time()]);

        $seed = (int) ($cfg['rng_seed'] ?? 0) > 0 ? (int) $cfg['rng_seed'] : random_int(1, 2147483647);
        mt_srand($seed);
        $p->meta('rng_seed', $seed);

        $plan = $this->buildPlan($cfg);
        $this->declareBars($p, $plan, $cfg);
        $p->phase('planning');

        // Los limites anti-flood del core impedirian crear contenido en rafaga.
        $flood = $this->suspendFloodChecks();
        $this->jobs->save($jobId, ['flood_saved' => $flood]);

        $completed = false;
        try {
            $completed = $this->executeSteps($cfg, $batch, $plan, $p);
        } finally {
            $this->restoreFloodChecks($flood);
            $this->jobs->save($jobId, ['flood_saved' => null]);
        }

        if ($completed) {
            $p->phase('done');
            $this->jobs->save($jobId, ['status' => 'done', 'phase' => 'done', 'finished_at' => time()]);

            return;
        }

        if ($p->purgeRequested()) {
            $this->jobs->save($jobId, ['status' => 'purging', 'phase' => 'purging']);
            $p->phase('purging');
            $result = $this->purge($actor, $batch, static function () use ($p): void {
                $p->tick();
            });
            foreach ($result['errors'] as $message) {
                $p->error('purge: ' . $message);
            }
            $p->phase('purged');
            $this->jobs->save($jobId, ['status' => 'cancelled', 'phase' => 'purged', 'finished_at' => time()]);

            return;
        }

        $p->phase('cancelled');
        $this->jobs->save($jobId, ['status' => 'cancelled', 'phase' => 'cancelled', 'finished_at' => time()]);
    }

    /** @return bool true si termino; false si se cancelo */
    private function executeSteps(array $cfg, string $batch, array $plan, JobProgress $p): bool
    {
        $p->phase('groups');
        $groupIds = $this->createGroups($cfg, $batch, $p);
        if ($p->cancelled()) {
            return false;
        }

        $p->phase('users');
        $userRows = $this->createUsers($cfg, $batch, $plan, $groupIds, $p);
        if ($p->cancelled()) {
            return false;
        }
        $p->settle('user_groups');

        $p->phase('structure');
        $forumRows = $this->createNodes($cfg, $batch, $p);
        if ($p->cancelled()) {
            return false;
        }

        $p->phase('moderators');
        $this->assignModerators($batch, $plan, $userRows, $forumRows, (array) ($cfg['labels'] ?? []), $p);
        if ($p->cancelled()) {
            return false;
        }
        $p->settle('groups', 'mod_assign');

        $p->phase('content');
        [$threadRefs, $replyRefs, $authors] = $this->createContent($batch, $plan, $userRows, $forumRows, $p);
        if ($p->cancelled()) {
            return false;
        }
        $p->settle('replies');

        $p->phase('reactions');
        $this->createReactions($cfg, $batch, $authors, $threadRefs, $replyRefs, $p);
        if ($p->cancelled()) {
            return false;
        }
        $p->settle('thread_reactions', 'reply_reactions');

        return true;
    }

    /**
     * Todas las decisiones aleatorias que definen los totales se toman de entrada,
     * asi las barras de progreso conocen su meta exacta desde el principio.
     */
    private function buildPlan(array $cfg): array
    {
        $forums = $cfg['categories'] * $cfg['forums_per_category'] * (1 + $cfg['subforums_per_forum']);
        $threads = $forums * $cfg['threads_per_forum'];

        $userGroupCounts = [];
        for ($i = 0; $i < $cfg['users']; $i++) {
            $userGroupCounts[] = $cfg['groups'] > 0 ? mt_rand(0, min(2, $cfg['groups'])) : 0;
        }

        $staff = $cfg['moderators'] > 0 && $cfg['users'] > 0 && $forums > 0;
        $mods = $staff ? min($cfg['moderators'], $cfg['users']) : 0;
        $modAssign = [];
        for ($i = 0; $i < $mods; $i++) {
            $modAssign[] = mt_rand(1, min(2, $forums));
        }

        $replyCounts = [];
        for ($t = 0; $t < $threads; $t++) {
            $replyCounts[] = $cfg['max_replies'] > 0 ? mt_rand(0, $cfg['max_replies']) : 0;
        }
        $replies = array_sum($replyCounts);

        return [
            'forums'          => $forums,
            'threads'         => $threads,
            'replies'         => $replies,
            'staff'           => $staff,
            'moderators'      => $mods,
            'userGroupCounts' => $userGroupCounts,
            'userGroupTotal'  => array_sum($userGroupCounts),
            'modAssignCounts' => $modAssign,
            'modAssignTotal'  => array_sum($modAssign),
            'replyCounts'     => $replyCounts,
            'threadReactions' => $this->reactionTarget((bool) $cfg['enable_thread_reactions'], (int) $cfg['thread_reaction_pct'], $threads),
            'replyReactions'  => $this->reactionTarget((bool) $cfg['enable_reply_reactions'], (int) $cfg['reply_reaction_pct'], $replies),
        ];
    }

    private function declareBars(JobProgress $p, array $plan, array $cfg): void
    {
        $p->declare('groups', self::BAR_LABELS['groups'], $cfg['groups'] + ($plan['staff'] ? 1 : 0));
        $p->declare('users', self::BAR_LABELS['users'], $cfg['users']);
        $p->declare('user_groups', self::BAR_LABELS['user_groups'], $plan['userGroupTotal']);
        $p->declare('categories', self::BAR_LABELS['categories'], $cfg['categories']);
        $p->declare('forums', self::BAR_LABELS['forums'], $plan['forums']);
        $p->declare('moderators', self::BAR_LABELS['moderators'], $plan['moderators']);
        $p->declare('mod_assign', self::BAR_LABELS['mod_assign'], $plan['modAssignTotal']);
        $p->declare('threads', self::BAR_LABELS['threads'], $plan['threads']);
        $p->declare('replies', self::BAR_LABELS['replies'], $plan['replies']);
        $p->declare('thread_reactions', self::BAR_LABELS['thread_reactions'], $plan['threadReactions']);
        $p->declare('reply_reactions', self::BAR_LABELS['reply_reactions'], $plan['replyReactions']);
    }

    // ------------------------------------------------------------------ pasos

    /** @return list<int> */
    private function createGroups(array $cfg, string $batch, JobProgress $p): array
    {
        $ids = [];
        for ($i = 1; $i <= $cfg['groups']; $i++) {
            if ($p->cancelled()) {
                break;
            }
            try {
                $name = $this->label($cfg, 'group', 'Seed Group') . " {$i}";
                $group = $this->groups->create(new CreateUserGroupDTO(
                    title: "{$name} ({$batch})",
                    description: 'Generated by Repli Seeder',
                    userTitle: $name,
                    bannerCssClass: 'userBanner--' . self::BANNER_PRESETS[($i - 1) % count(self::BANNER_PRESETS)],
                    bannerIcon: 'fas ' . self::BANNER_ICONS[($i - 1) % count(self::BANNER_ICONS)],
                    bannerText: $name,
                ));
                $ids[] = (int) $group->id;
                $this->track($batch, 'group', (int) $group->id);
                $p->ok('groups');
            } catch (\Throwable $e) {
                $p->fail('groups', 'group: ' . $e->getMessage());
            }
        }

        return $ids;
    }

    /** @return list<array{id:int,username:string}> */
    private function createUsers(array $cfg, string $batch, array $plan, array $groupIds, JobProgress $p): array
    {
        $rows = [];
        $n = 0;
        $attempts = 0;
        while (count($rows) < $cfg['users'] && $attempts < $cfg['users'] * 5) {
            if ($p->cancelled()) {
                break;
            }
            $attempts++;
            $n++;
            $username = $cfg['username_prefix'] . sprintf('%03d', $n);
            $email = $username . '@example.com';

            if ($this->userRepo->checkDuplicate($username, $email) !== null) {
                continue; // ya existe: probar el siguiente numero
            }

            try {
                $result = $this->registration->register(new RegisterUserDTO(
                    username: $username,
                    email: $email,
                    password: $cfg['password'],
                    passwordConfirm: $cfg['password'],
                    timezone: 'UTC',
                ));
            } catch (\Throwable $e) {
                $p->fail('users', "user {$username}: " . $e->getMessage());
                continue;
            }

            if (empty($result['success']) || $result['user'] === null) {
                $p->fail('users', "user {$username}: " . $this->flatten($result['errors'] ?? []));
                continue;
            }

            $userId = (int) $result['user']->id;
            // Sin verificacion de correo: la cuenta queda lista para iniciar sesion.
            $this->userRepo->updateState($userId, 'valid');

            $take = min((int) ($plan['userGroupCounts'][count($rows)] ?? 0), count($groupIds));
            $picked = $take > 0 ? (array) array_rand(array_flip($groupIds), $take) : [];
            foreach ($picked as $gid) {
                $this->groupRepo->addUserToGroup($userId, (int) $gid, false);
                $p->ok('user_groups');
            }

            $this->track($batch, 'user', $userId);
            $rows[] = ['id' => $userId, 'username' => $username];
            $p->ok('users');
        }

        return $rows;
    }

    /** @return list<array{id:int,title:string}> foros (los que pueden recibir hilos) */
    private function createNodes(array $cfg, string $batch, JobProgress $p): array
    {
        $forums = [];

        for ($c = 1; $c <= $cfg['categories']; $c++) {
            if ($p->cancelled()) {
                break;
            }
            $topic = self::TOPICS[($c - 1) % count(self::TOPICS)];
            $catId = $this->makeNode("{$topic} (Seed {$c})", 'category', 0, $batch, 'categories', $p);
            if ($catId === null) {
                continue;
            }

            for ($f = 1; $f <= $cfg['forums_per_category']; $f++) {
                if ($p->cancelled()) {
                    break 2;
                }
                $title = "{$topic} Forum {$f}";
                $forumId = $this->makeNode($title, 'forum', $catId, $batch, 'forums', $p);
                if ($forumId === null) {
                    continue;
                }
                $forums[] = ['id' => $forumId, 'title' => $title];

                for ($s = 1; $s <= $cfg['subforums_per_forum']; $s++) {
                    if ($p->cancelled()) {
                        break 3;
                    }
                    $subTitle = "{$title} / Sub {$s}";
                    $subId = $this->makeNode($subTitle, 'forum', $forumId, $batch, 'forums', $p);
                    if ($subId !== null) {
                        $forums[] = ['id' => $subId, 'title' => $subTitle];
                    }
                }
            }
        }

        return $forums;
    }

    private function makeNode(string $title, string $type, int $parentId, string $batch, string $bar, JobProgress $p): ?int
    {
        try {
            $id = $this->nodes->createNode(new CreateNodeDTO(
                title: $title,
                nodeTypeId: $type,
                parentId: $parentId,
                description: 'Generated by Repli Seeder',
            ));
            $this->track($batch, 'node', $id);
            $p->ok($bar);

            return $id;
        } catch (\Throwable $e) {
            $p->fail($bar, "node '{$title}': " . $e->getMessage());

            return null;
        }
    }

    private function assignModerators(string $batch, array $plan, array $userRows, array $forumRows, array $labels, JobProgress $p): void
    {
        if ($plan['moderators'] < 1 || $userRows === [] || $forumRows === []) {
            return;
        }

        // Grupo de staff para identificar a los moderadores sembrados.
        $staffGroupId = null;
        try {
            $staffName = $this->label(['labels' => $labels], 'staff', 'Seed Moderators');
            $staffBanner = $this->label(['labels' => $labels], 'staff_banner', 'Moderator');
            $staff = $this->groups->create(new CreateUserGroupDTO(
                title: "{$staffName} ({$batch})",
                description: 'Generated by Repli Seeder',
                userTitle: $staffBanner,
                bannerCssClass: 'userBanner--green',
                bannerIcon: 'fas fa-gavel',
                bannerText: $staffBanner,
                isStaffGroup: true,
            ));
            $staffGroupId = (int) $staff->id;
            $this->track($batch, 'group', $staffGroupId);
            $p->ok('groups');
        } catch (\Throwable $e) {
            $p->fail('groups', 'staff group: ' . $e->getMessage());
        }

        foreach (array_slice($userRows, 0, $plan['moderators']) as $i => $mod) {
            if ($p->cancelled()) {
                break;
            }
            try {
                if ($staffGroupId !== null) {
                    $this->groupRepo->addUserToGroup($mod['id'], $staffGroupId, false);
                }
                $howMany = max(1, min((int) ($plan['modAssignCounts'][$i] ?? 1), count($forumRows)));
                foreach ((array) array_rand($forumRows, $howMany) as $key) {
                    $nodeId = (int) $forumRows[$key]['id'];
                    $this->moderators->assign($mod['id'], $nodeId);
                    $this->track($batch, 'moderator', $mod['id'], $nodeId);
                    $p->ok('mod_assign');
                }
                $p->ok('moderators');
            } catch (\Throwable $e) {
                $p->fail('moderators', "moderator {$mod['username']}: " . $e->getMessage());
            }
        }
    }

    /**
     * @return array{0:list<array{id:int,author_id:int}>,1:list<array{id:int,author_id:int}>,2:list<array{id:int,username:string}>}
     */
    private function createContent(string $batch, array $plan, array $userRows, array $forumRows, JobProgress $p): array
    {
        $authors = $userRows !== [] ? $userRows : $this->existingUsers();
        $threadRefs = [];
        $replyRefs = [];
        if ($authors === []) {
            $p->error('@@no_users');

            return [$threadRefs, $replyRefs, $authors];
        }

        // Los hilos se numeran de corrido: cada uno tiene su cantidad de respuestas ya sorteada.
        $threadIndex = 0;
        $threadsPerForum = $plan['forums'] > 0 ? intdiv($plan['threads'], $plan['forums']) : 0;

        foreach ($forumRows as $forum) {
            for ($t = 0; $t < $threadsPerForum; $t++) {
                if ($p->cancelled()) {
                    return [$threadRefs, $replyRefs, $authors];
                }
                $replies = (int) ($plan['replyCounts'][$threadIndex++] ?? 0);

                $author = $authors[array_rand($authors)];
                $result = $this->threadCreator->create(new CreateThreadDTO(
                    nodeId: (int) $forum['id'],
                    userId: (int) $author['id'],
                    username: (string) $author['username'],
                    title: $this->sentence(mt_rand(3, 8), true),
                    message: $this->paragraphs(mt_rand(1, 3)),
                    ipAddress: '127.0.0.1',
                ));

                if (empty($result['success']) || empty($result['thread'])) {
                    $p->fail('threads', "thread in '{$forum['title']}': " . $this->flatten($result['errors'] ?? []));
                    continue;
                }

                $threadId = (int) $result['thread']->id;
                $this->track($batch, 'thread', $threadId, (int) $forum['id']);
                $threadRefs[] = ['id' => $threadId, 'author_id' => (int) $author['id']];
                $p->ok('threads');

                for ($r = 0; $r < $replies; $r++) {
                    if ($p->cancelled()) {
                        return [$threadRefs, $replyRefs, $authors];
                    }
                    $replier = $authors[array_rand($authors)];
                    $reply = $this->postReply->reply(new CreatePostDTO(
                        threadId: $threadId,
                        nodeId: (int) $forum['id'],
                        userId: (int) $replier['id'],
                        username: (string) $replier['username'],
                        message: $this->paragraphs(mt_rand(1, 2)),
                        ipAddress: '127.0.0.1',
                    ));
                    if (!empty($reply['success'])) {
                        if (!empty($reply['post'])) {
                            $replyRefs[] = ['id' => (int) $reply['post']->id, 'author_id' => (int) $replier['id']];
                        }
                        $p->ok('replies');
                    } else {
                        $p->fail('replies', "reply in thread #{$threadId}: " . $this->flatten($reply['errors'] ?? []));
                    }
                }
            }
        }

        return [$threadRefs, $replyRefs, $authors];
    }

    /**
     * Reacciones sobre un porcentaje de hilos y/o respuestas. Una reaccion por
     * elemento elegido, de un usuario distinto al autor y de cualquier tipo de
     * reaccion activa del sistema.
     *
     * @param list<array{id:int,username:string}> $authors
     * @param list<array{id:int,author_id:int}>   $threadRefs
     * @param list<array{id:int,author_id:int}>   $replyRefs
     */
    private function createReactions(
        array $cfg,
        string $batch,
        array $authors,
        array $threadRefs,
        array $replyRefs,
        JobProgress $p
    ): void {
        if (!$cfg['enable_thread_reactions'] && !$cfg['enable_reply_reactions']) {
            return;
        }

        $reactionIds = array_map(
            static fn ($def): int => (int) $def->reactionDefinitionId,
            $this->reactionDefs->findAllActive()
        );
        if ($reactionIds === []) {
            $p->error('@@no_reactions');

            return;
        }
        if (count($authors) < 2) {
            $p->error('@@few_users');

            return;
        }

        if ($cfg['enable_thread_reactions']) {
            $this->reactOn('thread', 'thread_reactions', $threadRefs, (int) $cfg['thread_reaction_pct'], $reactionIds, $authors, $batch, $p);
        }
        if ($cfg['enable_reply_reactions'] && !$p->cancelled()) {
            $this->reactOn('post', 'reply_reactions', $replyRefs, (int) $cfg['reply_reaction_pct'], $reactionIds, $authors, $batch, $p);
        }
    }

    /**
     * @param list<array{id:int,author_id:int}>   $refs
     * @param list<int>                           $reactionIds
     * @param list<array{id:int,username:string}> $authors
     */
    private function reactOn(
        string $contentType,
        string $bar,
        array $refs,
        int $pct,
        array $reactionIds,
        array $authors,
        string $batch,
        JobProgress $p
    ): void {
        $target = $this->reactionTarget(true, $pct, count($refs));
        $p->setTotal($bar, $target);
        if ($target === 0) {
            return;
        }

        $keys = array_keys($refs);
        shuffle($keys);

        foreach (array_slice($keys, 0, $target) as $key) {
            if ($p->cancelled()) {
                return;
            }
            $ref = $refs[$key];
            $candidates = array_values(array_filter(
                $authors,
                static fn (array $a): bool => (int) $a['id'] !== $ref['author_id']
            ));
            if ($candidates === []) {
                $p->fail($bar);
                continue;
            }

            $user = $candidates[array_rand($candidates)];
            $reactionId = $reactionIds[array_rand($reactionIds)];

            try {
                $res = $this->reactions->toggle(new ToggleReactionDTO($contentType, $ref['id'], (int) $user['id'], $reactionId));
            } catch (\Throwable $e) {
                $p->fail($bar, "reaction on {$contentType} #{$ref['id']}: " . $e->getMessage());
                continue;
            }

            if (!in_array($res['action'] ?? '', ['added', 'switched'], true)) {
                $p->fail($bar, "reaction on {$contentType} #{$ref['id']}: " . (string) ($res['error'] ?? ($res['action'] ?? 'error')));
                continue;
            }

            $rowId = (int) $this->db->fetchColumn(
                'SELECT id FROM reactions WHERE content_type = ? AND content_id = ? AND user_id = ?',
                [$contentType, $ref['id'], (int) $user['id']]
            );
            if ($rowId > 0) {
                $this->track($batch, 'reaction', $rowId);
            }
            $p->ok($bar);
        }
    }

    // ---------------------------------------------------------------- helpers

    /** Nombre traducido guardado en la configuracion del trabajo (con respaldo en ingles). */
    private function label(array $cfg, string $key, string $default): string
    {
        $value = trim((string) ($cfg['labels'][$key] ?? ''));

        return $value !== '' ? $value : $default;
    }

    /** Hilos o respuestas que recibiran reaccion: el porcentaje pedido, minimo 1 si hay alguno. */
    private function reactionTarget(bool $enabled, int $pct, int $total): int
    {
        if (!$enabled || $pct <= 0 || $total <= 0) {
            return 0;
        }

        return max(1, (int) round($total * $pct / 100));
    }

    /** @return list<array{id:int,username:string}> */
    private function existingUsers(): array
    {
        $rows = $this->db->fetchAll("SELECT id, username FROM users WHERE user_state = 'valid' ORDER BY id LIMIT 200");

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'username' => (string) $r['username']],
            $rows
        );
    }

    public function normalize(array $in): array
    {
        $d = self::defaults();
        $clamp = static fn (mixed $v, int $min, int $max, int $def): int => max($min, min($max, is_numeric($v) ? (int) $v : $def));
        // Porcentaje 0-100 en saltos de 10.
        $pct = static fn (mixed $v, int $def): int => (int) (round(max(0, min(100, is_numeric($v) ? (int) $v : $def)) / 10) * 10);

        $prefix = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($in['username_prefix'] ?? $d['username_prefix']));
        $password = (string) ($in['password'] ?? '');

        return [
            'categories'              => $clamp($in['categories'] ?? null, 0, 7, $d['categories']),
            'forums_per_category'     => $clamp($in['forums_per_category'] ?? null, 0, 10, $d['forums_per_category']),
            'subforums_per_forum'     => $clamp($in['subforums_per_forum'] ?? null, 0, 2, $d['subforums_per_forum']),
            'groups'                  => $clamp($in['groups'] ?? null, 0, 20, $d['groups']),
            'users'                   => $clamp($in['users'] ?? null, 0, 500, $d['users']),
            'moderators'              => $clamp($in['moderators'] ?? null, 0, 50, $d['moderators']),
            'threads_per_forum'       => $clamp($in['threads_per_forum'] ?? null, 0, 50, $d['threads_per_forum']),
            'max_replies'             => $clamp($in['max_replies'] ?? null, 0, 30, $d['max_replies']),
            'enable_thread_reactions' => !empty($in['enable_thread_reactions']),
            'thread_reaction_pct'     => $pct($in['thread_reaction_pct'] ?? null, $d['thread_reaction_pct']),
            'enable_reply_reactions'  => !empty($in['enable_reply_reactions']),
            'reply_reaction_pct'      => $pct($in['reply_reaction_pct'] ?? null, $d['reply_reaction_pct']),
            'username_prefix'         => ($prefix !== null && $prefix !== '') ? substr($prefix, 0, 12) : $d['username_prefix'],
            'password'                => strlen($password) >= 8 ? $password : $d['password'],
            'rng_seed'                => $clamp($in['rng_seed'] ?? null, 0, 2147483647, 0),
        ];
    }

    private function track(string $batch, string $type, int $entityId, ?int $extraId = null): void
    {
        $this->db->execute(
            'INSERT INTO ' . self::TABLE . ' (batch, entity_type, entity_id, extra_id, created_at) VALUES (?, ?, ?, ?, UNIX_TIMESTAMP())',
            [$batch, $type, $entityId, $extraId]
        );
    }

    /** @return array<string,string|null> valores originales para restaurar */
    private function suspendFloodChecks(): array
    {
        $saved = [];
        foreach (['flood_check_thread', 'flood_check_post'] as $key) {
            try {
                $current = $this->options->get($key);
                $saved[$key] = $current === null ? null : (string) $current;
                $this->options->set($key, '0');
            } catch (\Throwable) {
                // Si la opcion no existe, el core usa su valor por defecto.
            }
        }

        return $saved;
    }

    /** @param array<string,string|null> $saved */
    private function restoreFloodChecks(array $saved): void
    {
        foreach ($saved as $key => $value) {
            if ($value === null) {
                continue;
            }
            try {
                $this->options->set((string) $key, $value);
            } catch (\Throwable) {
            }
        }
    }

    /** Trabajo que dejo de dar senales de vida: se libera y se restauran las opciones anti-flood. */
    private function abandon(array $job): void
    {
        $saved = is_array($job['flood_saved'] ?? null) ? $job['flood_saved'] : [];
        $this->restoreFloodChecks($saved);

        $errors = (array) $job['errors'];
        $errors[] = '@@interrupted';
        $this->jobs->save((int) $job['id'], [
            'status'      => 'failed',
            'phase'       => 'interrupted',
            'errors'      => $errors,
            'flood_saved' => null,
            'finished_at' => time(),
        ]);
    }

    private function failJob(int $jobId, string $message): void
    {
        $job = $this->jobs->find($jobId);
        if ($job === null) {
            return;
        }
        $saved = is_array($job['flood_saved'] ?? null) ? $job['flood_saved'] : [];
        $this->restoreFloodChecks($saved);

        $errors = (array) $job['errors'];
        $errors[] = $message;
        $this->jobs->save($jobId, [
            'status'      => 'failed',
            'phase'       => 'failed',
            'errors'      => $errors,
            'flood_saved' => null,
            'finished_at' => time(),
        ]);
    }

    private function spawnWorker(int $jobId): ?int
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('exec') || in_array('exec', $disabled, true)) {
            return null;
        }

        $php = 'php';
        foreach ([PHP_BINDIR . '/php', '/usr/local/bin/php', '/usr/bin/php'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                $php = $candidate;
                break;
            }
        }
        $script = dirname(__DIR__, 2) . '/bin/worker.php';

        $output = [];
        @exec(
            'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $jobId . ' > /dev/null 2>&1 & echo $!',
            $output
        );
        $pid = isset($output[0]) ? (int) $output[0] : 0;

        return $pid > 0 ? $pid : null;
    }

    private function flatten(mixed $errors): string
    {
        if (is_string($errors)) {
            return $errors;
        }
        if (!is_array($errors)) {
            return 'unknown error';
        }
        $flat = [];
        array_walk_recursive($errors, static function ($v) use (&$flat): void {
            $flat[] = (string) $v;
        });

        return $flat === [] ? 'unknown error' : implode('; ', $flat);
    }

    private function sentence(int $words, bool $title = false): string
    {
        $parts = [];
        for ($i = 0; $i < $words; $i++) {
            $parts[] = self::WORDS[array_rand(self::WORDS)];
        }
        $text = implode(' ', $parts);

        return $title ? ucfirst($text) : ucfirst($text) . '.';
    }

    private function paragraphs(int $count): string
    {
        $paras = [];
        for ($p = 0; $p < $count; $p++) {
            $sentences = [];
            for ($s = 0, $n = mt_rand(2, 5); $s < $n; $s++) {
                $sentences[] = $this->sentence(mt_rand(6, 14));
            }
            $paras[] = implode(' ', $sentences);
        }

        return implode("\n\n", $paras);
    }
}
