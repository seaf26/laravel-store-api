# Tasks: API Resilience Completion

**Input**: Design documents from `specs/002-api-resilience-completion/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`,
`contracts/api-contract.md`, `quickstart.md`

**Tests**: Required for every behavior change. Focused tests must fail before the related
implementation is added.

## Format: `[ID] [P?] [Story?] Description`

- **[P]**: Different files and no incomplete dependency prevent parallel work.
- **[US1]**–**[US4]** map to the prioritized stories in `spec.md`.

## Phase 1: Setup and Baseline

- [x] T001 Record the 135-test/597-assertion baseline, branch, prior commit, and untracked-file ownership in `specs/002-api-resilience-completion/tasks.md`

---

## Phase 2: Foundational Decisions

- [x] T002 Confirm media cleanup identity, deterministic notification UUID inputs, database-first channel order, process barrier contract, and listing validation limits against `specs/002-api-resilience-completion/data-model.md`

---

## Phase 3: User Story 1 - Failure-Safe Product Images (Priority: P1)

**Goal**: Catalogue mutations never reference prematurely deleted files and failed cleanup remains retryable.

**Independent Test**: Force persistence and delete failures, then prove compensation and scheduled cleanup.

### Tests for User Story 1

- [x] T003 [P] [US1] Add create/update/delete compensation and pending-cleanup tests in `tests/Feature/Product/ProductImageSafetyTest.php`
- [x] T004 [P] [US1] Add cleanup command and schedule discovery tests in `tests/Feature/Maintenance/ProductImageCleanupTest.php`

### Implementation for User Story 1

- [x] T005 [US1] Add the additive pending-file-deletion migration in `database/migrations/2026_07_22_010000_create_pending_file_deletions_table.php`
- [x] T006 [US1] Add the cleanup model in `app/Models/PendingFileDeletion.php`
- [x] T007 [US1] Implement compensating create/update/delete and idempotent cleanup in `app/Services/Products/ProductImageService.php`
- [x] T008 [US1] Add bounded scheduled cleanup in `app/Console/Commands/CleanupProductImages.php` and `routes/console.php`
- [x] T009 [US1] Route product mutations through the image service in `app/Http/Controllers/Api/ProductController.php`
- [x] T010 [US1] Run focused product image and maintenance tests and reconcile documentation in `docs/reliability.md`

---

## Phase 4: User Story 2 - Race-Safe Notification Delivery (Priority: P1)

**Goal**: One recipient/event produces one durable in-app notification and at most one SMS attempt.

**Independent Test**: Retry all listeners and race one logical delivery against a shared database.

### Tests for User Story 2

- [x] T011 [P] [US2] Add deterministic ID and duplicate-key behavior tests in `tests/Unit/Services/Notifications/NotificationDeduplicatorTest.php`
- [x] T012 [P] [US2] Add listener retry, channel-order, SMS-count, and subscription-failure tests in `tests/Feature/Notification/NotificationDeduplicationTest.php`

### Implementation for User Story 2

- [x] T013 [US2] Implement deterministic database-backed delivery in `app/Services/Notifications/NotificationDeduplicator.php`
- [x] T014 [US2] Put the database channel before SMS in `app/Notifications/BackInStockNotification.php` and `app/Notifications/OrderStatusChangedNotification.php`
- [x] T015 [US2] Replace listener check-then-send/delete-first flows in `app/Listeners/SendNewProductNotifications.php`, `app/Listeners/SendBackInStockNotifications.php`, and `app/Listeners/SendOrderStatusNotification.php`
- [x] T016 [US2] Update existing notification regression tests for injected listeners in `tests/Feature/Notification/` and `tests/Feature/Order/OrderStatusTest.php`
- [x] T017 [US2] Run focused notification and order-status tests and update the deduplication boundary in `README.md`

---

## Phase 5: User Story 3 - Production-Database Concurrency Proof (Priority: P1)

**Goal**: Independent processes prove order locking and notification uniqueness on both production databases.

**Independent Test**: Two barrier-synchronized child processes race one unit and one logical notification.

### Tests and Harness for User Story 3

- [x] T018 [US3] Add the strict child-process worker protocol in `tests/Support/concurrent_worker.php`
- [x] T019 [US3] Add order and notification overlap scenarios with explicit SQLite/process skips in `tests/Feature/Concurrency/ProductionDatabaseConcurrencyTest.php`
- [x] T020 [US3] Extend production database jobs for MySQL 8 and PostgreSQL 16 in `.github/workflows/tests.yml`
- [x] T021 [US3] Run the concurrency scenarios in disposable MySQL and PostgreSQL containers and record outcomes in `specs/002-api-resilience-completion/tasks.md`

---

## Phase 6: User Story 4 - Predictable Listing Validation (Priority: P2)

**Goal**: Invalid product/order collection inputs receive field-specific 422 responses.

**Independent Test**: Exercise every invalid field and confirm valid listing behavior remains unchanged.

### Tests for User Story 4

- [x] T022 [P] [US4] Add invalid product filter, range, boolean, sort, and pagination tests in `tests/Feature/Product/ProductListingTest.php`
- [x] T023 [P] [US4] Add invalid order status, user filter, sort, and pagination tests in `tests/Feature/Order/OrderListingTest.php`

### Implementation for User Story 4

- [x] T024 [P] [US4] Add product collection validation in `app/Http/Requests/Product/IndexProductsRequest.php`
- [x] T025 [P] [US4] Add order collection validation and admin-only user filtering in `app/Http/Requests/Order/IndexOrdersRequest.php`
- [x] T026 [US4] Build product and order queries only from validated inputs in `app/Http/Controllers/Api/ProductController.php` and `app/Http/Controllers/Api/OrderController.php`
- [x] T027 [US4] Add accepted query values and representative validation errors to `README.md` and `docs/postman_collection.json`
- [x] T028 [US4] Run focused product/order listing tests in `tests/Feature/Product/ProductListingTest.php` and `tests/Feature/Order/OrderListingTest.php` and verify unchanged pagination response shapes

---

## Phase 7: Polish and Cross-Cutting Verification

- [x] T029 Validate `database/migrations/2026_07_22_010000_create_pending_file_deletions_table.php` add/down/reapply while preserving product, notification, subscription, and image state
- [x] T030 Run the complete SQLite suite from `phpunit.xml` and confirm only production concurrency scenarios skip
- [x] T031 Run the complete MySQL suite through `.github/workflows/tests.yml` settings and confirm both multi-process scenarios execute
- [x] T032 Run the complete PostgreSQL suite through `.github/workflows/tests.yml` settings and confirm both multi-process scenarios execute
- [x] T033 Run Pint, Composer strict validation/audit, PHP syntax, Postman JSON, workflow YAML, schedule, and `git diff --check`
- [x] T034 Review the final diff rooted at `.` for public-contract drift, filesystem rollback gaps, duplicate delivery windows, database portability, and unrelated user changes
- [x] T035 Update all task checkboxes and append exact verification results to `specs/002-api-resilience-completion/tasks.md`

---

## Dependencies and Execution Order

- T001–T002 establish the baseline and shared contracts.
- US1, US2, and US4 are source-file independent except for final README/controller integration.
- US3 depends on the US2 deduplicator and the existing order locks.
- Cross-cutting verification depends on every user story.

## Parallel Opportunities

- T003 and T004 affect separate test areas.
- T011 and T012 affect separate unit/feature tests.
- T022–T025 can be prepared in parallel by file ownership.
- MySQL and PostgreSQL verification environments may run independently.

## Implementation Strategy

1. Protect product media and notification delivery first because they close integrity gaps.
2. Build the real concurrency harness on the finished delivery/order primitives.
3. Add strict listing validation without changing successful collection behavior.
4. Verify every database family and operational failure path before commit/push.

## Baseline Record

- Branch: `feature/docs`.
- Prior commit: `afe855a feat: harden auth and order reliability`.
- Tests: 135 passed, 597 assertions on SQLite; the previous batch also passed on MySQL 8.
- User-owned untracked file: `STUDY_NOTES.md` (must remain excluded).
- Graphify output is local and ignored.

## Completion Verification (2026-07-22)

- Focused changed-behavior regression: 63 tests passed, 233 assertions.
- SQLite full suite: 154 tests; 152 passed, 2 production-engine concurrency tests skipped,
  688 assertions.
- MySQL 8 full suite: 154 tests passed, 705 assertions.
- PostgreSQL 16 full suite: 154 tests passed, 705 assertions.
- Independent-process concurrency gate: 2 tests passed and 17 assertions on each of MySQL
  8 and PostgreSQL 16 with skipped tests treated as failures.
- Pending-file-deletion migration was rolled back and reapplied against a disposable SQLite
  database while product image paths, notification rows, and stock subscriptions remained.
- Pint, PHP syntax, strict Composer validation, locked dependency audit, Postman JSON,
  workflow YAML, scheduler discovery, and `git diff --check` passed.
- `STUDY_NOTES.md` remains untracked and excluded from the implementation.
