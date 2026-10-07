<?php

declare(strict_types=1);

namespace Accord\Examples;

/**
 * The record lock of the control API's `/hold-record`, `/held` and `/release` (PROFILE.md), test-only.
 *
 * The held transaction must outlive the request that starts it, and `php -S` serves each request
 * from any of several worker processes. So the transaction lives in a helper process of its own
 * (`hold-record.php`, started detached with `setsid`): it opens its own connection, runs
 * `begin; select record from records where record = $1 for update`, writes its backend pid to a
 * state file and waits on a Unix socket. `/release` (from any worker) connects to that socket; the
 * helper rolls back, answers, and exits. The state directory is keyed by the database URL, so
 * servers on other databases never see each other's hold. A flock serialises hold and release.
 * The helper gives up (rolls back) on its own after an hour, so a stopped server leaks nothing.
 *
 * Self-contained (no autoloader) because the helper process loads it directly.
 */
final class RecordHold
{
    private const int START_TIMEOUT_S = 15;
    private const int RELEASE_TIMEOUT_S = 30;
    private const int IDLE_LIMIT_S = 3600;

    public function __construct(private readonly string $databaseUrl) {}

    /**
     * Locks the record in a transaction of the helper process.
     *
     * @return array{0: int, 1: array<string, mixed>|\stdClass} [status, body]
     */
    public function hold(string $record): array
    {
        return $this->locked(function () use ($record): array {
            if ($this->heldPid() !== null) {
                if ($this->ping()) {
                    return [409, ['error' => 'a record is already held']];
                }
            }
            $this->cleanup();
            $cmd = 'exec setsid ' . escapeshellarg(self::phpCli()) . ' ' . escapeshellarg(__DIR__ . '/hold-record.php') . ' '
                . escapeshellarg($this->dir()) . ' ' . escapeshellarg($record) . ' </dev/null >/dev/null 2>&1 &';
            $env = getenv();
            $env['ACCORD_HOLD_DATABASE_URL'] = $this->databaseUrl;
            $proc = proc_open(['/bin/sh', '-c', $cmd], [], $pipes, null, $env);
            if ($proc === false) {
                return [500, ['error' => 'cannot start the hold helper']];
            }
            proc_close($proc); // the shell only: the helper runs on, detached
            $deadline = microtime(true) + self::START_TIMEOUT_S;
            while (microtime(true) < $deadline) {
                $status = $this->status();
                if ($status !== null && str_starts_with($status, 'held ')) {
                    return [200, new \stdClass()];
                }
                if ($status === 'missing') {
                    $this->cleanup();

                    return [404, ['error' => 'unknown record']];
                }
                if ($status !== null && str_starts_with($status, 'error ')) {
                    $this->cleanup();

                    return [500, ['error' => substr($status, 6)]];
                }
                usleep(5_000);
            }

            return [500, ['error' => 'the hold helper did not start']];
        });
    }

    /** How many sessions the held transaction is blocking (0 when nothing is held). */
    public function waiting(\PDO $pdo): int
    {
        $pid = $this->heldPid();
        if ($pid === null) {
            return 0;
        }
        $st = $pdo->prepare('select count(*) as n from pg_stat_activity where ?::int = any(pg_blocking_pids(pid))');
        $st->execute([$pid]);

        return (int) $st->fetchColumn();
    }

    /** Rolls the held transaction back; returns once it has ended. Nothing held: nothing to do. */
    public function release(): void
    {
        $this->locked(function (): void {
            if ($this->heldPid() === null) {
                return;
            }
            $conn = @stream_socket_client('unix://' . $this->dir() . '/sock', $errno, $error, 5);
            if ($conn === false) {
                $this->cleanup(); // the helper is gone, and its transaction with it

                return;
            }
            stream_set_timeout($conn, self::RELEASE_TIMEOUT_S);
            fwrite($conn, "release\n");
            $answer = fgets($conn);
            fclose($conn);
            if ($answer !== "done\n") {
                throw new \RuntimeException('the hold helper did not confirm the release');
            }
            $this->cleanup();
        });
    }

    /**
     * The helper process (hold-record.php): holds the lock until released.
     *
     * @param list<string> $argv
     */
    public static function serve(array $argv): int
    {
        [, $dir, $record] = $argv + [null, '', ''];
        $url = getenv('ACCORD_HOLD_DATABASE_URL');
        $write = static function (string $status) use ($dir): void {
            file_put_contents("$dir/status.tmp", $status);
            rename("$dir/status.tmp", "$dir/status");
        };
        try {
            $pdo = self::connect(\is_string($url) ? $url : '');
            $pdo->beginTransaction();
            $st = $pdo->prepare('select record from records where record = ? for update');
            $st->execute([$record]);
            if ($st->fetchColumn() === false) {
                $pdo->rollBack();
                $write('missing');

                return 0;
            }
            $q = $pdo->prepare('select pg_backend_pid()');
            $q->execute();
            $pid = (int) $q->fetchColumn();
            @unlink("$dir/sock");
            $server = stream_socket_server("unix://$dir/sock", $errno, $error);
            if ($server === false) {
                throw new \RuntimeException("cannot listen on $dir/sock: $error");
            }
            $write("held $pid");
            $deadline = time() + self::IDLE_LIMIT_S;
            while (true) {
                $conn = @stream_socket_accept($server, max(1, $deadline - time()));
                if ($conn === false) {
                    $pdo->rollBack(); // nobody released it: give up

                    return 1;
                }
                $line = fgets($conn);
                if ($line === "release\n") {
                    $pdo->rollBack();
                    @unlink("$dir/status");
                    @unlink("$dir/sock");
                    fwrite($conn, "done\n");
                    fclose($conn);

                    return 0;
                }
                fwrite($conn, "held\n"); // a liveness check
                fclose($conn);
            }
        } catch (\Throwable $e) {
            $write('error ' . $e->getMessage());

            return 1;
        }
    }

    private function dir(): string
    {
        $dir = sys_get_temp_dir() . '/accord-hold-' . substr(hash('sha256', $this->databaseUrl), 0, 16);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o700, true);
        }

        return $dir;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $fn
     *
     * @return T
     */
    private function locked(\Closure $fn): mixed
    {
        $lock = fopen($this->dir() . '/lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('cannot open the hold lock');
        }
        try {
            flock($lock, LOCK_EX);

            return $fn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function status(): ?string
    {
        $s = @file_get_contents($this->dir() . '/status');

        return $s === false ? null : $s;
    }

    private function heldPid(): ?int
    {
        $s = $this->status();

        return $s !== null && str_starts_with($s, 'held ') ? (int) substr($s, 5) : null;
    }

    /** Whether the helper still answers on its socket. */
    private function ping(): bool
    {
        $conn = @stream_socket_client('unix://' . $this->dir() . '/sock', $errno, $error, 2);
        if ($conn === false) {
            return false;
        }
        fwrite($conn, "ping\n");
        $ok = fgets($conn) === "held\n";
        fclose($conn);

        return $ok;
    }

    private function cleanup(): void
    {
        @unlink($this->dir() . '/status');
        @unlink($this->dir() . '/sock');
    }

    /** postgres://user:password@host:port/database (a query string is ignored but `sslmode`). */
    private static function connect(string $url): \PDO
    {
        $p = parse_url($url);
        if ($p === false || !\in_array($p['scheme'] ?? '', ['postgres', 'postgresql'], true)) {
            throw new \InvalidArgumentException('the database URL must look like postgres://user:password@host:5432/database');
        }
        $dsn = 'pgsql:host=' . ($p['host'] ?? 'localhost') . ';port=' . ($p['port'] ?? 5432) . ';dbname=' . ltrim(rawurldecode($p['path'] ?? ''), '/');
        parse_str($p['query'] ?? '', $q);
        if (\is_string($q['sslmode'] ?? null)) {
            $dsn .= ';sslmode=' . $q['sslmode'];
        }

        return new \PDO($dsn, isset($p['user']) ? rawurldecode($p['user']) : null, isset($p['pass']) ? rawurldecode($p['pass']) : null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /** The PHP CLI binary: under PHP-FPM, PHP_BINARY is php-fpm, which cannot run a script. */
    private static function phpCli(): string
    {
        if (\PHP_SAPI === 'cli') {
            return PHP_BINARY;
        }
        $candidate = PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        return is_executable($candidate) ? $candidate : (is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php');
    }
}
