# Changelog

All notable changes to the SGP backend are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project adheres to the plan gates in `download/04_Plan_de_desarrollo.md`.

## [0.1.0] - 2026-09-26

### Added — Phase 0 (Arranque e infraestructura, Sprint 1)

- Laravel 12 modular monolith bootstrap under `backend/` with the 12
  architecture modules and per-layer directory structure.
- `Shared` module: `ClockInterface`, `SequenceGeneratorInterface` and
  `TransactionManager` contracts; `Money`, `CubanIdentityNumber`, `Period`
  and `SystemClock` value objects with full unit coverage (banker's
  rounding, DECIMAL(12,2) overflow guards, structural CI validation).
- Authentication skeleton (RF-SEG-001): Sanctum token issuance via
  `POST /api/v1/auth/login`, `/auth/me`, `/auth/logout`, rate limited,
  with feature tests against real MySQL.
- CI pipeline (GitHub Actions): Pint, PHPStan level 8, deptrac module
  boundaries, Pest + coverage thresholds, Docker build.
- Infrastructure: multi-stage `Dockerfile`, `docker-compose.yml`
  (app/web/db/queue/scheduler), Nginx configuration, `Makefile`.
- Repository governance: `CONTRIBUTING.md`, `CODEOWNERS`, PR template.
- Open question registered: P-08 (identity number check digit algorithm
  pending official confirmation from the Ministry).
