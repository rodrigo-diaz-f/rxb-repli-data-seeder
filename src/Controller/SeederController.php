<?php

declare(strict_types=1);

namespace Repli\Seeder\Controller;

use App\Domain\Language\Service\PhraseService;
use App\Http\Support\SuperAdminGate;
use App\System\Http\Request;
use App\System\Http\Response;
use App\System\TemplateEngine;
use Repli\Seeder\Service\SeederService;

final class SeederController
{
    private const HOME = '/admin/repli-seeder';
    private const P = 'repli_seeder_';

    /** Etiquetas de las barras (clave => ingles por defecto); la traduccion sale de las frases. */
    private const BAR_LABELS = [
        'groups' => 'User groups', 'users' => 'Users', 'user_groups' => 'Group memberships',
        'categories' => 'Categories', 'forums' => 'Forums and subforums', 'moderators' => 'Moderators',
        'mod_assign' => 'Moderator assignments', 'threads' => 'Threads', 'replies' => 'Replies',
        'thread_reactions' => 'Thread reactions', 'reply_reactions' => 'Reply reactions',
    ];

    /** Mensajes fijos que el servicio guarda como "@@codigo" para traducirlos al mostrarlos. */
    private const ERROR_TOKENS = [
        'spawn'       => 'Could not start the background process. Is exec() disabled in PHP?',
        'interrupted' => 'The background process stopped unexpectedly (container restarted or killed).',
        'ended'       => 'The background process ended unexpectedly.',
        'no_users'    => 'There are no users available to create threads.',
        'no_reactions' => 'There are no active reactions in the system.',
        'few_users'   => 'At least 2 users are needed to react to content from others.',
    ];

    public function __construct(
        private readonly TemplateEngine $template,
        private readonly SeederService $seeder,
        private readonly PhraseService $phrases,
    ) {
    }

    /**
     * Pagina principal. Mientras haya una generacion en curso, esta y todas las
     * demas URLs del addon muestran su avance en lugar del formulario.
     */
    public function index(Request $request): Response
    {
        if ($denied = SuperAdminGate::deny($request, $this->phrases)) {
            return $denied;
        }

        return $this->page($request);
    }

    /** Inicia una generacion (con advertencia previa si se estima demasiado larga). */
    public function run(Request $request): Response
    {
        if ($denied = SuperAdminGate::deny($request, $this->phrases)) {
            return $denied;
        }
        if ($this->seeder->activeJob() !== null) {
            return Response::redirect(self::HOME);
        }

        $input = [];
        foreach (array_keys(SeederService::defaults()) as $key) {
            $input[$key] = $request->postParam($key);
        }
        $cfg = $this->seeder->normalize($input);

        $estimate = $this->seeder->estimate($cfg);
        if ($estimate['seconds'] > SeederService::WARN_SECONDS && (string) $request->postParam('confirmed') !== '1') {
            return $this->render($request, [
                'view'     => 'warning',
                'estimate' => $estimate,
                'duration' => SeederService::humanDuration($estimate['seconds']),
                'limit'    => SeederService::humanDuration(SeederService::WARN_SECONDS),
                'hidden'   => $this->hiddenFields($cfg),
            ]);
        }

        $actor = $request->getAttribute('currentUser');
        $started = $this->seeder->startJob($cfg, $actor !== null ? (int) $actor->id : 0, $this->groupLabels());
        if (isset($started['error'])) {
            $message = $started['error'] === 'busy'
                ? $this->t('error_busy', 'There is already a generation in progress.')
                : $this->t('err_spawn', self::ERROR_TOKENS['spawn']);

            return $this->page($request, ['startError' => $message]);
        }

        return Response::redirect(self::HOME);
    }

    /** Estimacion en vivo para el formulario (JSON). */
    public function estimate(Request $request): Response
    {
        if ($denied = SuperAdminGate::deny($request, $this->phrases)) {
            return $denied;
        }

        $estimate = $this->seeder->estimate($request->query());

        return Response::json([
            'entities' => $estimate['entities'],
            'seconds'  => $estimate['seconds'],
            'duration' => SeederService::humanDuration($estimate['seconds']),
            'warn'     => $estimate['seconds'] > SeederService::WARN_SECONDS,
        ]);
    }

    /** Estado de la generacion para el sondeo de la pagina de avance (JSON). */
    public function status(Request $request): Response
    {
        if ($denied = SuperAdminGate::deny($request, $this->phrases)) {
            return $denied;
        }

        $job = $this->seeder->activeJob() ?? $this->seeder->latestJob();

        return Response::json($job === null ? ['finished' => true, 'bars' => []] : $this->payload($job));
    }

    /** Cancela la generacion en curso: conservando lo creado o purgando el lote. */
    public function cancel(Request $request): Response
    {
        if ($denied = SuperAdminGate::deny($request, $this->phrases)) {
            return $denied;
        }

        $this->seeder->requestCancel((string) $request->postParam('mode') === 'purge');

        return Response::redirect(self::HOME);
    }

    public function purge(Request $request): Response
    {
        if ($denied = SuperAdminGate::deny($request, $this->phrases)) {
            return $denied;
        }
        if ($this->seeder->activeJob() !== null) {
            return Response::redirect(self::HOME);
        }

        $actor = $request->getAttribute('currentUser');
        $result = $this->seeder->purge($actor !== null ? (int) $actor->id : 0);
        $result['errors'] = $this->translateErrors($result['errors']);

        return $this->page($request, ['purgeResult' => $result]);
    }

    private function page(Request $request, array $extra = []): Response
    {
        $active = $this->seeder->activeJob();
        if ($active !== null) {
            return $this->render($request, [
                'view' => 'progress',
                'job'  => $this->payload($active),
            ]);
        }

        $last = $this->seeder->lastJobToShow();

        return $this->render($request, array_merge([
            'view'      => 'form',
            'last'      => $last !== null ? $this->payload($last) : null,
            'lastLabel' => $last !== null ? $this->lastLabel($last) : '',
        ], $extra));
    }

    private function render(Request $request, array $data): Response
    {
        return Response::html($this->template->render('Repli/Seeder:repli_seeder/index', array_merge([
            'form'      => SeederService::defaults(),
            'adminUser' => $request->getAttribute('currentUser'),
            'barLabels' => $this->barLabels(),
            'i18nJson'  => json_encode($this->jsPhrases(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP),
        ], $data)));
    }

    /** Estado listo para mostrar: con los mensajes fijos de error ya traducidos. */
    private function payload(array $job): array
    {
        $payload = $this->seeder->statusPayload($job);
        $payload['errors'] = $this->translateErrors((array) $payload['errors']);

        return $payload;
    }

    /** @param list<string> $errors */
    private function translateErrors(array $errors): array
    {
        return array_map(function (string $message): string {
            if (str_starts_with($message, '@@')) {
                $code = substr($message, 2);
                if (isset(self::ERROR_TOKENS[$code])) {
                    return $this->t('err_' . $code, self::ERROR_TOKENS[$code]);
                }
            }

            return $message;
        }, $errors);
    }

    private function lastLabel(array $job): string
    {
        return match ((string) $job['status']) {
            'done'      => $this->t('last_completed', 'Completed'),
            'failed'    => $this->t('last_failed', 'Failed'),
            'cancelled' => ($job['phase'] ?? '') === 'purged'
                ? $this->t('last_cancelled_purged', 'Cancelled and purged')
                : $this->t('last_cancelled', 'Cancelled'),
            default     => (string) $job['status'],
        };
    }

    /** @return array<string,string> */
    private function barLabels(): array
    {
        $out = [];
        foreach (self::BAR_LABELS as $key => $default) {
            $out[$key] = $this->t('bar_' . $key, $default);
        }

        return $out;
    }

    /** Textos que usa el JavaScript de la pagina (avance y estimacion en vivo). */
    private function jsPhrases(): array
    {
        return [
            'status' => [
                'queued'     => $this->t('status_queued', 'Starting...'),
                'running'    => $this->t('status_running', 'Generating data'),
                'cancelling' => $this->t('status_cancelling', 'Cancelling...'),
                'purging'    => $this->t('status_purging', 'Cancelling and purging this batch...'),
            ],
            'elapsed'      => $this->t('elapsed', 'Elapsed'),
            'remaining'    => $this->t('remaining', 'Remaining'),
            'errorsWord'   => $this->t('errors_word', 'errors'),
            'estimateLine' => $this->t('estimate_line', 'Estimated: ~{entities} entities, ~{duration}'),
            'estimateWarn' => $this->t('estimate_warn', ' (you will be asked to confirm)'),
            'bars'         => $this->barLabels(),
        ];
    }

    /** Nombres de los grupos generados, en el idioma del admin que inicia la generacion. */
    private function groupLabels(): array
    {
        return [
            'group'        => $this->t('group_name', 'Seed Group'),
            'staff'        => $this->t('staff_group_name', 'Seed Moderators'),
            'staff_banner' => $this->t('staff_banner', 'Moderator'),
        ];
    }

    /** Frase traducida (con el ingles como respaldo) y marcadores {nombre} reemplazados. */
    private function t(string $key, string $default, array $replace = []): string
    {
        return strtr($this->phrases->phrase(self::P . $key, $default), $replace);
    }

    /** @return array<string,string> campos ocultos para reenviar la configuracion confirmada */
    private function hiddenFields(array $cfg): array
    {
        $out = ['confirmed' => '1'];
        foreach ($cfg as $key => $value) {
            if (is_bool($value)) {
                if ($value) {
                    $out[$key] = '1';
                }
                continue;
            }
            $out[$key] = (string) $value;
        }

        return $out;
    }
}
