<?php

declare(strict_types=1);

namespace Accord\Symfony\Routing;

use Accord\Symfony\Controller\SyncController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * The sync routes under `accord.prefix`. Import them in `config/routes/accord.yaml`:
 *
 *     accord:
 *         resource: .
 *         type: accord
 */
final class AccordRouteLoader extends Loader
{
    /** The sync API's paths, relative to the prefix. Any method: the handler answers each itself. */
    public const array PATHS = ['/health', '/v1/push', '/v1/pull'];

    public function __construct(private readonly string $prefix)
    {
        parent::__construct();
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();
        $prefix = trim($this->prefix, '/');
        foreach (self::PATHS as $path) {
            $routes->add(
                'accord.' . trim(str_replace('/', '.', $path), '.'),
                new Route(($prefix === '' ? '' : "/$prefix") . $path, ['_controller' => SyncController::class, '_accord_path' => $path, '_stateless' => true]),
            );
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'accord';
    }
}
