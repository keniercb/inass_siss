# Contributing to the SGP backend

This document summarizes the working rules that keep the pipeline green and
the architecture honest. The authoritative references are the project docs
in `download/` (functional requirements, architecture, data model,
development plan).

## Repository layout

```
backend/                     Laravel 12 application (the product)
  app/Modules/<Module>/      12 modules, 4 layers each (Domain/Application/Infrastructure/Presentation)
  app/Modules/<M>/Tests/     module-owned tests (Unit: Pest closures; Feature: PHPUnit classes)
download/                    project documentation (living documents)
scripts/                     repository tooling
```

## Definition of Done (applies to every PR)

1. **Pint** formats the code (`vendor/bin/pint`).
2. **PHPStan level 8** reports no errors (`vendor/bin/phpstan analyse`).
3. **deptrac** finds no unauthorized module dependency (`vendor/bin/deptrac analyse`).
4. **Pest** suite is green against real MySQL 8.4 (ADR-08: never SQLite).
5. Coverage: >= 80 % global, >= 90 % domain/application (RNF-007); the
   `Shared` module is held to >= 95 %.
6. The behavior of the change is covered by tests written first (TDD).

## Branching and commits

- Branches are short-lived: `feat/SGP-<issue>`, `fix/SGP-<issue>`,
  `refactor/SGP-<issue>`, `docs/SGP-<issue>`, `test/SGP-<issue>`.
- **Conventional Commits** messages: `feat:`, `fix:`, `refactor:`, `test:`,
  `docs:`, `chore:` (scope optional: `feat(auth): ...`).
- One logical change per PR; squash-merge into `main`.
- PRs require the CI pipeline green plus review by the module CODEOWNER.

## Testing conventions

- Module unit tests live in `app/Modules/<M>/Tests/Unit` as Pest closure
  tests with datasets; they must not bootstrap Laravel.
- Module feature tests live in `app/Modules/<M>/Tests/Feature` as PHPUnit
  classes extending `Tests\TestCase` (this avoids per-directory `uses()`
  binding while keeping the module ownership of the tests).
- Tabular business rules (state machine, calculation datasets, permission
  matrix) are expressed as Pest datasets, never as loops inside tests.
- Time and sequence numbers come from `ClockInterface` /
  `SequenceGeneratorInterface` fakes; never from `now`/direct queries.

## Domain invariants that reviewers must enforce

- Money is `Money`/`DECIMAL(12,2)`; no floats, no arithmetic on scalars.
- Natural uniqueness (identity number, case number, control number) is
  guaranteed by database constraints, not only by application checks.
- Versioned settings (RN-007): calculations always record the settings
  version they used.
- The audit log and state history are append-only (RN-010).
