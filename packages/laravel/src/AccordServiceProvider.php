<?php

declare(strict_types=1);

namespace Accord\Laravel;

use Accord\Laravel\Console\CompactCommand;
use Accord\Laravel\Console\MigrateCommand;
use Accord\Laravel\Http\AccordController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Accord server into Laravel: `config/accord.php`, the sync routes under the configured
 * prefix (outside the "web" group: no session, cookies or CSRF), `accord:migrate` and
 * `accord:compact`, and compaction in the scheduler. All sync behaviour lives in accordsync/server.
 */
final class AccordServiceProvider extends ServiceProvider
{
    /** The sync API's paths, relative to the prefix. The handler answers 404/405-like cases itself. */
    public const array PATHS = ['/health', '/v1/push', '/v1/pull'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/accord.php', 'accord');
        $this->app->singleton(Accord::class, static function (Container $app): Accord {
            $config = $app->make('config');
            \assert($config instanceof Config);

            return new Accord($app, $config);
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/accord.php' => $this->app->configPath('accord.php')], 'accord-config');

        if (!($this->app instanceof \Illuminate\Contracts\Foundation\CachesRoutes && $this->app->routesAreCached())) {
            $this->registerRoutes();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([MigrateCommand::class, CompactCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            /** @var Config $config */
            $config = $this->app->make('config');
            if (!filter_var($config->get('accord.schedule', true), FILTER_VALIDATE_BOOL) || $config->get('accord.definition') === null) {
                return;
            }
            $cron = self::cron($this->app->make(Accord::class)->definition()->compaction->intervalMs);
            if ($cron !== null) {
                $schedule->command('accord:compact')->cron($cron)->withoutOverlapping();
            }
        });
    }

    /** A cron expression for a compaction interval: whole minutes under an hour, else whole hours. */
    public static function cron(int $intervalMs): ?string
    {
        if ($intervalMs <= 0) {
            return null;
        }
        $minutes = max(1, (int) round($intervalMs / 60_000));
        if ($minutes < 60) {
            return $minutes === 1 ? '* * * * *' : "*/$minutes * * * *";
        }
        $hours = (int) round($minutes / 60);

        return match (true) {
            $hours <= 1 => '0 * * * *',
            $hours < 24 => "0 */$hours * * *",
            default => '0 0 * * *',
        };
    }

    private function registerRoutes(): void
    {
        /** @var Config $config */
        $config = $this->app->make('config');
        $prefix = $config->get('accord.prefix', 'accord');
        $prefix = trim(\is_string($prefix) ? $prefix : '', '/');
        /** @var list<string> $middleware */
        $middleware = (array) $config->get('accord.middleware', []);
        /** @var Router $router */
        $router = $this->app->make('router');
        foreach (self::PATHS as $path) {
            $router->any(($prefix === '' ? '' : "/$prefix") . $path, AccordController::class)
                ->middleware($middleware)
                ->defaults('accord_path', $path)
                ->name('accord.' . trim(str_replace('/', '.', $path), '.'));
        }
    }
}
