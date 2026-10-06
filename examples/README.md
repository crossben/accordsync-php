# Example apps

Two minimal apps that serve Accord with the conformance profile (`contract/conformance/profile.json`)
and expose the test-only control API. CI runs the Accord server conformance suite against each.

| App | Framework | Sync API | Control API |
| --- | --- | --- | --- |
| `laravel-app` | Laravel 13, `accordsync/laravel` | `http://127.0.0.1:8845/accord` | `http://127.0.0.1:8846` |
| `symfony-app` | Symfony 7.4 + DBAL, `accordsync/symfony` | `http://127.0.0.1:8847/accord` | `http://127.0.0.1:8848` |

`shared/ConformanceProfile.php` builds the definition from `profile.json` and implements the control
API; the apps only route to it. Never deploy them: the control API resets the database.

```sh
docker run -d --name accord-pg -e POSTGRES_USER=accord -e POSTGRES_PASSWORD=accord -e POSTGRES_DB=accord -p 55472:5432 postgres:16-alpine

cd examples/laravel-app && composer install && ./serve.sh        # DB_URL in .env (copied from .env.example)
cd examples/symfony-app && composer install && ./serve.sh        # DATABASE_URL in .env

# from the Accord repository's app/ folder:
ACCORD_URL=http://127.0.0.1:8845/accord ACCORD_CONTROL_URL=http://127.0.0.1:8846 pnpm --filter @accordsync/conformance test
ACCORD_URL=http://127.0.0.1:8847/accord ACCORD_CONTROL_URL=http://127.0.0.1:8848 pnpm --filter @accordsync/conformance test
```

Both apps can share one database, but don't run the suite against both at the same time: each test
resets it. `serve.sh` uses PHP's built-in server with 16 workers (ADR-P10).
