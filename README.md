# SkillServe backend (Laravel 13)

The REST API behind the SkillServe admin web (`/api/*`) and the mobile app (`/api/client/v1/*`),
plus Reverb for realtime. Project overview, setup and the module map are in the repository root
`README.md`; deployment is in `DEPLOYMENT.md`.

## Layout

- `app/Modules/<Name>/` — one folder per feature module (controllers, requests, services,
  resources, policies, routes, `Tests/Feature`). Module routes are included from `routes/api.php`;
  policies and event listeners are registered in `app/Providers/AppServiceProvider.php`.
- `app/Shared/` — base classes, the `{ success, message, data, errors, meta }` response envelope,
  shared helpers and the OpenAPI schemas (`app/Shared/Swagger`).
- `deploy/` — the Render start script, nginx and PHP settings.

## Everyday commands (from the repository root)

```bash
docker compose exec backend php artisan test            # in-memory SQLite, never the dev database
docker compose exec backend ./vendor/bin/pint --dirty    # formatting
docker compose exec backend php artisan l5-swagger:generate && php api-docs/generate.php
```

Swagger UI: `http://localhost:8000/api/documentation`.
