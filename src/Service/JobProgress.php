<?php

declare(strict_types=1);

namespace Repli\Seeder\Service;

/**
 * Contadores en memoria de una generacion. Se vuelcan a la base de datos como
 * maximo cada ~0.7 s; en cada volcado tambien se renueva el latido y se lee si
 * el usuario pidio cancelar.
 */
final class JobProgress
{
    private const FLUSH_EVERY = 0.7;
    private const MAX_ERRORS = 30;

    /** @var array<string,array{key:string,label:string,total:int,done:int,failed:int}> */
    private array $bars = [];
    private array $meta = [];
    /** @var list<string> */
    private array $errors = [];
    private string $phase = '';
    private float $lastFlush = 0.0;
    private bool $cancel = false;
    private bool $purge = false;

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly int $jobId,
    ) {
    }

    public function declare(string $key, string $label, int $total): void
    {
        $this->bars[$key] = ['key' => $key, 'label' => $label, 'total' => $total, 'done' => 0, 'failed' => 0];
    }

    public function setTotal(string $key, int $total): void
    {
        if (isset($this->bars[$key])) {
            $this->bars[$key]['total'] = $total;
        }
    }

    /** Ajusta el total de barras "derivadas" a lo realmente procesado. */
    public function settle(string ...$keys): void
    {
        foreach ($keys as $key) {
            if (isset($this->bars[$key])) {
                $this->bars[$key]['total'] = $this->bars[$key]['done'] + $this->bars[$key]['failed'];
            }
        }
    }

    public function ok(string $key, int $n = 1): void
    {
        if (isset($this->bars[$key])) {
            $this->bars[$key]['done'] += $n;
        }
        $this->tick();
    }

    public function fail(string $key, string $message = ''): void
    {
        if (isset($this->bars[$key])) {
            $this->bars[$key]['failed']++;
        }
        if ($message !== '') {
            $this->error($message);
        }
        $this->tick();
    }

    public function error(string $message): void
    {
        if (count($this->errors) < self::MAX_ERRORS) {
            $this->errors[] = $message;
        }
    }

    public function meta(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    public function done(string $key): int
    {
        return $this->bars[$key]['done'] ?? 0;
    }

    public function phase(string $phase): void
    {
        $this->phase = $phase;
        $this->flush();
    }

    /** true si el usuario pidio cancelar (se consulta como maximo cada ~0.7 s). */
    public function cancelled(): bool
    {
        $this->tick();

        return $this->cancel;
    }

    public function purgeRequested(): bool
    {
        return $this->purge;
    }

    public function tick(): void
    {
        if (microtime(true) - $this->lastFlush >= self::FLUSH_EVERY) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $this->lastFlush = microtime(true);
        $this->jobs->save($this->jobId, [
            'phase'     => $this->phase,
            'progress'  => ['bars' => array_values($this->bars), 'meta' => $this->meta],
            'errors'    => $this->errors,
            'heartbeat' => time(),
        ]);
        $flags = $this->jobs->flags($this->jobId);
        $this->cancel = $flags['cancel'];
        $this->purge = $flags['purge'];
    }
}
