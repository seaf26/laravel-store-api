# Implementation Plan: API Resilience Completion

**Branch**: `feature/docs` | **Date**: 2026-07-22 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/002-api-resilience-completion/spec.md`

## Summary

Finish the audit backlog without changing public success contracts: move product image
mutation into a compensating service backed by durable pending deletion records; replace
notification check-then-send logic with deterministic database notification identifiers;
prove locking with barrier-synchronized child processes on MySQL and PostgreSQL; and
validate product/order collection parameters through dedicated request objects.

## Technical Context

**Language/Version**: PHP 8.3+

**Primary Dependencies**: Laravel 13.20, Sanctum 4, PHPUnit 12, Symfony Process 7.4,
Pint 1

**Storage**: SQLite for fast tests; MySQL 8 and PostgreSQL 16 for production-locking CI;
the configured `public` filesystem disk for product images

**Testing**: PHPUnit feature/unit tests, independent PHP child processes, MySQL and
PostgreSQL service containers, Pint, Composer validation/audit, PHP syntax checks

**Target Platform**: Linux HTTP API, queue workers, and scheduler workers

**Performance Goals**: Listing validation adds no additional application queries except
administrator `user_id` existence validation; notification deduplication uses one primary
key insert rather than a read-before-write check; cleanup processes bounded batches

**Constraints**: Preserve endpoint success shapes and authorization; production delivery
is Git-only; storage and database cannot share a transaction; external SMS remains
best-effort; SQLite must not claim row-lock proof

**Scale/Scope**: One additive cleanup migration, two small services, one cleanup command,
two listing requests, three listener rewrites, one multi-process harness, two production
database CI jobs, focused documentation

## Constitution Check

*GATE: Passed before Phase 0 and re-checked after Phase 1 design.*

- [x] Public query inputs receive field-specific validation before domain queries.
- [x] Cross-storage failure ordering and compensating cleanup are explicit and durable.
- [x] Notification deduplication uses a database uniqueness boundary rather than a race-prone check.
- [x] Concurrency proof uses independent processes and production locking databases.
- [x] Every changed failure, retry, and authorization path has a focused test task.
- [x] Migrations are additive, preserve existing data, and have a data-preserving rollback.
- [x] Public validation and operational documentation changes are identified.
- [x] The design uses existing framework primitives and dependencies with bounded new abstractions.

Post-design re-check: all gates pass. A pending-file-deletion table is the smallest durable
way to bridge filesystem/database failures. Deterministic notification UUIDs reuse the
existing notifications primary key, avoiding a second delivery-ledger migration.

## Project Structure

### Documentation (this feature)

```text
specs/002-api-resilience-completion/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── api-contract.md
├── checklists/
│   ├── requirements.md
│   └── resilience.md
└── tasks.md
```

### Source Code (repository root)

```text
app/
├── Console/Commands/CleanupProductImages.php
├── Http/Requests/
│   ├── Order/IndexOrdersRequest.php
│   └── Product/IndexProductsRequest.php
├── Listeners/
├── Models/PendingFileDeletion.php
└── Services/
    ├── Notifications/NotificationDeduplicator.php
    └── Products/ProductImageService.php
database/migrations/
routes/console.php
tests/
├── Feature/Concurrency/ProductionDatabaseConcurrencyTest.php
├── Feature/Notification/
├── Feature/Product/
└── Support/concurrent_worker.php
.github/workflows/tests.yml
README.md
docs/reliability.md
```

**Structure Decision**: Keep controllers and listeners thin. Product image ordering and
compensation form one reusable domain operation, while notification identity generation
is shared by three listeners. The child worker lives under tests because it is executable
test infrastructure, not an application command.

## Phase 0: Research Decisions

Research is recorded in [research.md](research.md). Decisions cover cross-storage cleanup,
deterministic notification identities, database-first channel order, multi-process test
barriers, cross-database CI, and collection validation.

## Phase 1: Design

- Add `pending_file_deletions` with a unique `(disk, path)` identity. Product create stores
  the file first and compensates on persistence failure. Replace/delete commits database
  state and a cleanup obligation together, then attempts deletion after commit; failures
  remain scheduled for retry.
- Add a cleanup command that processes bounded pending rows. Missing files count as
  successful idempotent cleanup. Schedule it frequently enough to bound orphan lifetime.
- Derive an RFC 4122-compatible deterministic UUID from notification class, recipient,
  and logical event key. Set it before delivery and put the database channel first. The
  existing notifications primary key atomically selects one worker; a duplicate-key error
  is a clean no-op and prevents later SMS execution.
- Back-in-stock subscriptions are deleted only after a new or already-existing durable
  notification is confirmed. Other delivery errors leave them for retry.
- Add a production-only feature test that uses two Symfony Process children, ready files,
  a release barrier, strict timeouts, and JSON result files. Exercise one-unit ordering and
  notification identity races against shared MySQL/PostgreSQL databases.
- Extend CI with PostgreSQL and ensure both production database jobs execute the overlap
  group. SQLite explicitly skips these tests.
- Add dedicated product/order index Form Requests. Controllers construct queries only
  from validated data; regular users are prohibited from supplying the administrator-only
  user filter.

## Verification Strategy

1. Add focused failing tests for file compensation/deferred cleanup, deterministic
   delivery, subscription retry, multi-process order/notification overlap, and invalid
   listing values.
2. Implement each story independently and run its focused tests.
3. Run the complete SQLite suite, which must show explicit skips only for real concurrency.
4. Run the complete suite against disposable MySQL and PostgreSQL containers and confirm
   no concurrency tests skip.
5. Run Pint, Composer validation/audit, PHP syntax, migration lifecycle, documentation,
   workflow syntax, and diff checks.

## Migration Safety and Rollback

- The new table is additive and does not alter product, notification, or subscription rows.
- Existing files require no backfill; only future cleanup obligations use the table.
- The unique disk/path key makes repeated scheduling idempotent.
- Rollback drops only pending cleanup metadata and never deletes a product image.
- Before production rollback, operators must process or export outstanding cleanup rows;
  no production migration or rollback is authorized in this task.

## Complexity Tracking

| Decision | Why Needed | Simpler Alternative Rejected Because |
| --- | --- | --- |
| Durable pending file deletion table | Filesystem deletion can fail after a valid database commit | Immediate deletion alone either risks broken references or leaves untracked orphans |
| Test child-process worker | A single PHPUnit process cannot prove cross-connection row locks | Sequential stale-model tests do not overlap transactions |
