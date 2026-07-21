# Implementation Plan: API Reliability Hardening

**Branch**: `feature/docs` | **Date**: 2026-07-22 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/001-api-reliability-hardening/spec.md`

## Summary

Harden the existing Store API without changing its architecture: use named, privacy-safe
request limiters; atomically claim OTP rows and serialize issuance; lock and reload orders
inside status transactions; associate idempotency keys with canonical request hashes; and
make the scheduled maintenance, dependency requirements, CI gates, and public docs match
the behavior.

## Technical Context

**Language/Version**: PHP 8.3+

**Primary Dependencies**: Laravel 13.20, Sanctum 4, PHPUnit 12, Pint 1

**Storage**: SQLite for local/fast tests; MySQL or PostgreSQL for production locking
semantics; database-backed cache and queues by default

**Testing**: PHPUnit feature/unit tests, MySQL CI compatibility job, Pint, Composer
validation/audit, PHP syntax checks

**Target Platform**: Linux HTTP API and queue/scheduler workers

**Project Type**: Single Laravel REST API

**Performance Goals**: Limiter checks and request hashing add no external network calls;
idempotent replays remain a single indexed lookup plus order loading

**Constraints**: Preserve current success responses and authorization; use Git-only
delivery; no production deployment; migration must preserve existing idempotency rows;
SQLite cannot prove row-lock behavior

**Scale/Scope**: Five abuse-sensitive endpoint groups, one additive migration, two domain
services, focused regression tests, CI and README/Postman updates

## Constitution Check

*GATE: Passed before Phase 0 and re-checked after Phase 1 design.*

- [x] Public trust boundaries, rate limits, authorization, and negative paths are identified.
- [x] State invariants and concurrency controls are explicit and database-backed.
- [x] Behavior changes have focused test coverage, including relevant failure and retry paths.
- [x] Public API contracts and documentation changes are identified.
- [x] The design uses the smallest Laravel-native solution; no complexity exception is needed.
- [x] Migration, production-database verification, and rollback requirements are addressed.

Post-design re-check: all gates still pass. The request-hash column remains nullable at
the database level after backfill to avoid a heavier SQLite table rebuild/MySQL locking
step; application writes always provide the hash, and the migration validates/backfills
legacy rows.

## Project Structure

### Documentation (this feature)

```text
specs/001-api-reliability-hardening/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── api-contract.md
├── checklists/
│   └── requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
app/
├── Exceptions/
├── Providers/AppServiceProvider.php
└── Services/
    ├── Orders/
    └── Otp/
database/migrations/
routes/
├── api.php
└── console.php
tests/
├── Feature/Auth/
├── Feature/Order/
└── Unit/Services/Orders/
.github/workflows/tests.yml
docs/postman_collection.json
README.md
composer.json
```

**Structure Decision**: Preserve the existing Laravel layout. Add one small order-request
fingerprint class because the canonicalization is a named domain invariant used by order
placement/tests. Keep limiter registration in the existing provider and transaction logic
inside the existing services. Do not add repositories, modules, or infrastructure layers.

## Phase 0: Research Decisions

Research is recorded in [research.md](research.md). All technical questions are resolved:
named limiter registration, response headers, HMAC limiter keys, cache-lock issuance,
compare-and-set OTP claims, order row locking, canonical request hashing, migration
backfill, and 409 exception rendering.

## Phase 1: Design

- Add five named limiter policies: login, phone-verification issue/attempt, and password
  reset issue/attempt. Each combines independent source and HMAC-phone buckets and returns
  a stable JSON `429` with framework-generated retry headers.
- OTP issuance uses a short distributed cache lock keyed by HMAC(phone + purpose), then a
  retryable database transaction for limit check, prior-code invalidation, and insert. SMS
  delivery happens after commit and lock release.
- OTP redemption verifies the latest usable hash, then atomically claims it with a
  conditional update; only an update count of one succeeds.
- Order status changes reload and lock the order inside the transaction before no-op and
  transition checks. Cancellation stock work and history use only the locked instance.
- Canonical idempotency payloads aggregate integer quantities by product ID, numeric-sort
  IDs, serialize fixed-key rows, and hash with SHA-256. Existing matching keys replay;
  mismatches throw the documented 409 domain exception.
- Add a nullable 64-character request-hash column, backfill legacy rows in bounded chunks
  using a migration-frozen canonicalizer, and drop only that column on rollback.
- Add daily OTP pruning and make CI/docs reflect the runtime and new error behavior.

## Verification Strategy

1. Write focused failing tests for throttling headers/limits, OTP claim behavior,
   idempotency matching/conflicts, stale status instances, and duplicate cancellation.
2. Run focused test groups after each behavior slice.
3. Run the full SQLite suite and Pint locally.
4. Run Composer validation/audit and PHP syntax checks.
5. Configure CI to run the suite on SQLite and MySQL; the MySQL job validates production
   SQL compatibility. A true overlapping-process lock test remains a named follow-up if it
   cannot be made deterministic in this batch, and documentation must not claim that a
   sequential test proves concurrency.

## Migration Safety and Rollback

- Migration is additive, preserves all existing rows, and backfills only null hashes.
- Hash computation is frozen in the migration rather than calling mutable application
  code.
- Keeping the database column nullable avoids a second table rewrite/locking DDL step.
- New application writes always set a hash; legacy rows are backfilled before migration
  completion.
- Rollback drops only `idempotency_keys.request_hash`; no order, item, key, or stock data is
  deleted.
- No production migration is authorized by this plan.

## Complexity Tracking

No constitution violations or complexity exceptions.
