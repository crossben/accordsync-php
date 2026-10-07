<?php

declare(strict_types=1);

namespace App\Accord;

use Accord\Examples\ConformanceProfile;
use Accord\Symfony\Accord;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** The conformance control API (test-only; answers only when ACCORD_CONTROL_ENABLED is true). */
final class ControlController
{
    public function __construct(
        private readonly Accord $accord,
        #[Autowire(env: 'bool:default::ACCORD_CONTROL_ENABLED')]
        private readonly ?bool $enabled,
        #[Autowire(env: 'default::ACCORD_DATABASE_URL')]
        private readonly ?string $accordDatabaseUrl,
        #[Autowire(env: 'default::DATABASE_URL')]
        private readonly ?string $databaseUrl,
    ) {}

    #[Route('/{action}', requirements: ['action' => 'token|reset|compact|age-device|hold-record|held|release'], methods: ['GET', 'POST'], stateless: true)]
    public function __invoke(Request $request, string $action): JsonResponse
    {
        if ($this->enabled !== true) {
            throw new NotFoundHttpException();
        }
        $query = $request->server->get('QUERY_STRING');
        [$status, $body] = (new ConformanceProfile())->control(
            $request->getMethod(),
            $action,
            ConformanceProfile::query(\is_string($query) ? $query : ''),
            $this->accord->pdo(...),
            $this->accord->rateLimiter(),
            $this->accord->compact(...),
            // The connection of /hold-record: the database Accord uses (its own URL, else Doctrine's).
            $this->accordDatabaseUrl !== null && $this->accordDatabaseUrl !== '' ? $this->accordDatabaseUrl : (string) $this->databaseUrl,
        );

        return new JsonResponse($body, $status);
    }
}
