# Tasks: API Reliability Hardening

**Input**: Design documents from `specs/001-api-reliability-hardening/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`,
`contracts/api-contract.md`, `quickstart.md`

**Tests**: Required for every behavior change. Focused tests must fail before the related
implementation is added.

## Format: `[ID] [P?] [Story?] Description`

- **[P]**: Different files and no incomplete dependency prevent parallel work.
- **[US1]**, **[US2]**, **[US3]** map to the prioritized stories in `spec.md`.

## Phase 1: Setup and Baseline

**Purpose**: Preserve the existing state and make the acceptance baseline explicit.

- [x] T001 Record the passing 108-test baseline, current Pint failures, branch, and untracked-file ownership in `specs/001-api-reliability-hardening/tasks.md`

---

## Phase 2: Foundational Decisions

**Purpose**: Lock the cross-story contract and migration approach before behavior edits.

- [x] T002 Confirm limiter names/limits, idempotency 409 shape, nullable-backfill migration, and verification commands against `specs/001-api-reliability-hardening/contracts/api-contract.md`

**Checkpoint**: User-story work can proceed in parallel with non-overlapping ownership.

---

## Phase 3: User Story 1 - Abuse-Resistant Account Access (Priority: P1) 🎯 MVP

**Goal**: Bound authentication attempts and guarantee one successful OTP claim.

**Independent Test**: Authentication endpoints throttle with retry headers, known/unknown
issue responses remain indistinguishable, and a valid OTP succeeds only once.

### Tests for User Story 1

- [x] T003 [P] [US1] Add login source/phone throttling and retry-header tests in `tests/Feature/Auth/LoginTest.php`
- [x] T004 [P] [US1] Add verification issue/attempt throttling, privacy, replacement, and atomic-claim regression tests in `tests/Feature/Auth/PhoneVerificationTest.php`
- [x] T005 [P] [US1] Add password-reset issue/attempt throttling and response-parity tests in `tests/Feature/Auth/PasswordResetTest.php`

### Implementation for User Story 1

- [x] T006 [US1] Register privacy-safe named limiter policies and stable 429 responses in `app/Providers/AppServiceProvider.php`
- [x] T007 [US1] Attach login, verification, registration, forgot-password, and reset limiter middleware in `routes/api.php`
- [x] T008 [US1] Serialize OTP issuance and atomically claim usable codes in `app/Services/Otp/OtpService.php`
- [x] T009 [US1] Run the focused authentication suite and reconcile the public behavior with `specs/001-api-reliability-hardening/contracts/api-contract.md`

**Checkpoint**: User Story 1 is independently functional and testable.

---

## Phase 4: User Story 2 - Deterministic Order Mutations (Priority: P1)

**Goal**: Make idempotent retries payload-aware and evaluate statuses from committed state.

**Independent Test**: Equivalent reordered retries replay; changed payloads conflict with
no mutation; stale duplicate cancellation restores stock once.

### Tests for User Story 2

- [x] T010 [P] [US2] Add canonical fingerprint unit tests in `tests/Unit/Services/Orders/OrderRequestFingerprintTest.php`
- [x] T011 [P] [US2] Add reordered replay, mismatched-payload conflict, long-key, and migration-backfill tests in `tests/Feature/Order/OrderIdempotencyTest.php`
- [x] T012 [P] [US2] Add stale-transition and duplicate-stale-cancellation tests in `tests/Feature/Order/OrderStatusTest.php`

### Implementation for User Story 2

- [x] T013 [US2] Implement deterministic item canonicalization and SHA-256 hashing in `app/Services/Orders/OrderRequestFingerprint.php`
- [x] T014 [US2] Add the renderable 409 conflict exception in `app/Exceptions/IdempotencyConflictException.php`
- [x] T015 [US2] Add and backfill nullable request hashes with a safe down path in `database/migrations/2026_07_22_000000_add_request_hash_to_idempotency_keys_table.php`
- [x] T016 [US2] Expose request hashes in the idempotency model fillable contract in `app/Models/IdempotencyKey.php`
- [x] T017 [US2] Enforce matching fingerprints and 64-character key validation in `app/Services/Orders/OrderService.php` and `app/Http/Controllers/Api/OrderController.php`
- [x] T018 [US2] Lock/reload orders before transition decisions and refresh no-op responses in `app/Services/Orders/OrderStatusService.php` and `app/Http/Controllers/Api/OrderController.php`
- [x] T019 [US2] Run focused order tests and validate migration up/down behavior against `specs/001-api-reliability-hardening/quickstart.md`

**Checkpoint**: User Story 2 is independently functional and testable.

---

## Phase 5: User Story 3 - Trustworthy Maintenance Signals (Priority: P2)

**Goal**: Align maintenance, dependency, CI, and documentation signals with runtime behavior.

**Independent Test**: Scheduled pruning is listed, CI runs all declared gates, setup docs
match the runtime, and SMS text is valid UTF-8.

### Tests for User Story 3

- [x] T020 [P] [US3] Add back-in-stock SMS encoding coverage in `tests/Feature/Notification/BackInStockNotificationTest.php`
- [x] T021 [P] [US3] Add schedule discovery coverage in `tests/Feature/Maintenance/ScheduledMaintenanceTest.php`

### Implementation for User Story 3

- [x] T022 [P] [US3] Schedule daily model pruning in `routes/console.php`
- [x] T023 [P] [US3] Correct the back-in-stock SMS punctuation in `app/Notifications/BackInStockNotification.php`
- [x] T024 [P] [US3] Declare PHP 8.3/BCMath and project metadata in `composer.json` and refresh `composer.lock`
- [x] T025 [US3] Add Composer validation/audit, Pint, BCMath, and MySQL compatibility gates in `.github/workflows/tests.yml`
- [x] T026 [US3] Update runtime, storage, throttling, pruning, idempotency conflict, and test documentation in `README.md`
- [x] T027 [US3] Add 409 and throttling examples to `docs/postman_collection.json`

**Checkpoint**: User Story 3 is independently verifiable.

---

## Phase 6: Polish and Cross-Cutting Verification

- [x] T028 Apply project formatting to task-owned PHP files under `app/`, `tests/`, `database/`, and `routes/`, then confirm `vendor/bin/pint --test` passes
- [x] T029 Validate and audit the dependency manifests `composer.json` and `composer.lock`
- [x] T030 Run PHP syntax checks for `app/`, `tests/`, `database/`, `routes/`, `config/`, and `bootstrap/`, then run the complete suite from `phpunit.xml`
- [x] T031 Review the final repository diff rooted at `.` for public-contract drift, migration rollback safety, unrelated changes, and unresolved constitution violations
- [x] T032 Update every completed checkbox and verification result in `specs/001-api-reliability-hardening/tasks.md`

---

## Dependencies and Execution Order

### Phase Dependencies

- Setup and decisions (T001-T002) precede implementation.
- US1 and US2 are independent and may run in parallel after T002.
- US3 is independent at the source-file level and may run in parallel, but its README and
  Postman tasks must describe the final US1/US2 contract.
- Polish and verification depend on all selected stories.

### Within Each Story

- Tests are written and observed failing before implementation.
- US1: T003-T005 → T006-T008 → T009.
- US2: T010-T012 → T013-T018 → T019.
- US3: T020-T021 → T022-T027.

### Parallel Opportunities

- T003, T004, and T005 touch separate authentication test files.
- T010, T011, and T012 touch separate order test files.
- US1, US2, and the US3 tooling/documentation slice have non-overlapping primary ownership.
- T022, T023, and T024 touch separate implementation/configuration files.

## Parallel Execution Example

```text
Worker A: T003-T009 — authentication throttling and OTP atomicity
Worker B: T010-T019 — order idempotency and status locking
Worker C: T020-T027 — maintenance, dependency, CI, and documentation alignment
Fresh verifier: T028-T032 — formatting, full checks, diff review, confirmed fixes
```

## Implementation Strategy

1. Complete the security-critical US1 and order-integrity US2 slices in parallel.
2. Complete US3 while preserving the exact contracts produced by US1/US2.
3. Integrate all slices without broad refactors.
4. Run a fresh full verification pass and fix only confirmed findings.
5. Do not deploy or run any production migration in this task.

## Baseline Record

- Branch at start: `feature/docs`.
- Tests at start: 108 passed, 334 assertions.
- Pint at start: four files failed formatting checks.
- Pre-existing untracked user file: `STUDY_NOTES.md`.
- Generated task-owned artifacts: `.agents/`, `.claude/`, `.specify/`, `specs/`, and
  `graphify-out/`.

## Final Verification Record

- SQLite: 135 tests passed, 597 assertions.
- MySQL 8.0.46: 135 tests passed, 597 assertions in a disposable local container.
- Pint, Composer strict validation, locked dependency audit, PHP syntax checks, Postman
  JSON, workflow YAML, route middleware inspection, scheduler inspection, and
  `git diff --check` all passed.
- Additive idempotency migration add/backfill/down/reapply passed; rollback preserved the
  existing order, items, and key.
- Fresh verification fixed two formatter violations and an OTP issue-guard response-parity
  edge case; no findings remain.
- Remaining limitation: the suite does not launch two truly simultaneous request
  processes, and PostgreSQL/GitHub-hosted Actions were not executed locally.

---

## Phase 7: Convergence

- [x] T033 [US3] Publish an authoritative, version-controlled reliability architecture view that includes `idempotency_keys.request_hash` in `docs/reliability.md` and link it from `README.md` per FR-006 and Constitution IV (partial)
- [x] T034 [US2] Document the current idempotency fingerprint comparison, matching replay, mismatched `409`, and status-lock transaction flows in `docs/reliability.md`, while labeling the older PNGs as overview snapshots in `README.md` per FR-007 and Constitution IV (contradicts)

### Convergence Verification Record

- Unit suite: 12 tests passed, 18 assertions.
- Complete SQLite suite: 135 tests passed, 597 assertions.
- Pint, Composer strict validation, locked dependency audit, PHP syntax checks, Postman
  JSON, scheduler inspection, and `git diff --check` passed after the documentation update.
- Result: implementation and documentation are converged for the specified feature scope.
