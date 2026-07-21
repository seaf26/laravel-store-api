# Feature Specification: API Reliability Hardening

**Feature Branch**: `feature/docs`

**Created**: 2026-07-22

**Status**: Approved for planning

**Input**: Harden authentication, OTP redemption, order status transitions, and order
idempotency; add the related quality and documentation gates identified in the project
audit.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Abuse-Resistant Account Access (Priority: P1)

As a customer, I can register, verify my phone, log in, and reset my password without an
attacker being able to make unlimited credential or one-time-code attempts. A valid
one-time code can produce at most one successful action, even when requests overlap.

**Why this priority**: These endpoints protect customer accounts and are publicly
reachable without authentication.

**Independent Test**: Repeated login, code-request, code-verification, and password-reset
attempts reach documented limits and return a retryable throttling response; two attempts
to redeem one valid code yield exactly one success.

**Acceptance Scenarios**:

1. **Given** repeated invalid login attempts for the same client/account combination,
   **When** the configured limit is exceeded, **Then** further attempts receive `429` and
   retry timing information.
2. **Given** repeated one-time-code issue or verification attempts, **When** their
   respective limits are exceeded, **Then** further attempts receive `429` without
   revealing whether the phone number belongs to an account.
3. **Given** one valid, unused code, **When** two redemption requests overlap, **Then**
   exactly one request succeeds and the other reports an invalid or expired code.
4. **Given** a newly issued code, **When** an older code for the same phone and purpose is
   submitted, **Then** the older code is rejected.

---

### User Story 2 - Deterministic Order Mutations (Priority: P1)

As a customer or administrator, retrying or concurrently changing an order never creates
an ambiguous result, illegal state transition, duplicate stock movement, or unexpected
order replay.

**Why this priority**: Order and stock correctness directly affect fulfilment and customer
trust.

**Independent Test**: Order retries compare the complete normalized request, and stale or
overlapping status changes are evaluated against the latest committed order state.

**Acceptance Scenarios**:

1. **Given** a completed order request with an idempotency key, **When** the same customer
   resubmits the same normalized items with that key, **Then** the original order is
   returned without another stock decrement.
2. **Given** a completed order request with an idempotency key, **When** the same customer
   submits different normalized items with that key, **Then** the request receives `409`
   and no order or stock data changes.
3. **Given** two order instances read from an earlier state, **When** one request changes
   the committed status first, **Then** the second request is validated against the new
   status rather than its stale copy.
4. **Given** an order is cancelled, **When** a stale duplicate cancellation is submitted,
   **Then** stock is restored only once and no duplicate status history is written.

---

### User Story 3 - Trustworthy Maintenance Signals (Priority: P2)

As a maintainer, automated checks and project documentation accurately describe the
runtime, public behavior, scheduled cleanup, and formatting state so defects are caught
before review.

**Why this priority**: Reliable delivery depends on reproducible requirements and quality
signals, but this work follows the customer-facing correctness fixes.

**Independent Test**: The documented setup succeeds with the declared requirements,
scheduled cleanup is discoverable, and automated checks reject test, formatting,
dependency-validation, or known-vulnerability failures.

**Acceptance Scenarios**:

1. **Given** a proposed change, **When** automated review runs, **Then** tests, formatting,
   dependency validation, and vulnerability checks all run and any failure blocks the job.
2. **Given** the documented local setup, **When** a maintainer follows it, **Then** the
   declared runtime and extensions match the application requirements.
3. **Given** expired or consumed one-time-code records, **When** daily maintenance runs,
   **Then** records beyond the documented retention window are removed.
4. **Given** a back-in-stock notification, **When** its SMS text is generated, **Then** the
   message contains correctly encoded punctuation.

### Edge Cases

- Rate-limit keys must not expose raw phone numbers in cache or diagnostic output.
- Unknown and known phone numbers receive the same public response from code-request and
  forgot-password operations, including when throttled.
- Reordering identical order items produces the same idempotency fingerprint.
- Existing idempotency records created before this feature remain replayable and gain a
  fingerprint derived from their recorded order items.
- An idempotency key longer than the supported maximum is rejected rather than silently
  ignored.
- A stale status request that is no longer legal fails without changing stock or history.
- Local fast tests may use a lightweight database, but concurrency claims require a
  production-compatible database verification path.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST throttle login attempts by both client source and normalized
  account identifier, returning `429` with retry timing after five attempts per minute.
- **FR-002**: The system MUST throttle one-time-code issue operations to three attempts per
  ten minutes per normalized phone/purpose and also impose a client-source ceiling.
- **FR-003**: The system MUST throttle one-time-code verification and password-reset
  attempts to five attempts per minute per client source and normalized phone.
- **FR-004**: A one-time code MUST transition from unused to consumed through a single
  atomic claim, allowing at most one successful redemption.
- **FR-005**: Issuing a code MUST serialize the rate-limit check, invalidation of earlier
  codes, and creation of the replacement code for the same phone and purpose.
- **FR-006**: Each accepted idempotency key MUST be associated with a deterministic
  fingerprint of the normalized order items.
- **FR-007**: Reusing an idempotency key with a matching fingerprint MUST replay the
  original order; a mismatched fingerprint MUST return `409` without mutation.
- **FR-008**: Idempotency keys longer than 64 characters MUST return a validation error.
- **FR-009**: Every order status change MUST be evaluated using the latest committed order
  state and MUST write the status, stock restoration, and history atomically.
- **FR-010**: Repeating the current committed order status MUST remain a no-op.
- **FR-011**: Expired or consumed one-time-code records older than one day MUST be pruned by
  a daily scheduled task.
- **FR-012**: Automated review MUST run the full tests, formatting check, dependency-file
  validation, and locked-dependency vulnerability audit.
- **FR-013**: Runtime documentation MUST declare the actual minimum runtime, directly used
  extensions, storage behavior, rate-limit behavior, idempotency conflict response, and
  scheduled-maintenance requirement.
- **FR-014**: The back-in-stock SMS MUST use valid UTF-8 punctuation.

### Security and Reliability Requirements

- **SR-001**: Throttle identifiers MUST be one-way derived and MUST NOT store raw phone
  numbers in rate-limit keys.
- **SR-002**: Public account-recovery and verification-request responses MUST remain
  indistinguishable for known and unknown phone numbers.
- **SR-003**: Concurrent or stale order mutations MUST NOT produce an illegal transition,
  more than one cancellation restock, or more than one history row for a no-op.
- **SR-004**: The idempotency fingerprint migration MUST preserve existing records and
  provide a safe rollback that removes only the newly added fingerprint data.
- **SR-005**: No new endpoint, status code, or error shape may be introduced without a
  matching automated assertion and documentation update.

### Key Entities

- **One-Time Code**: A purpose-scoped, expiring secret with an unused/consumed lifecycle.
- **Rate-Limit Bucket**: A privacy-preserving counter for a client source, normalized phone,
  action, and time window.
- **Idempotency Record**: The association between a customer, key, normalized request
  fingerprint, and original order.
- **Order Status History**: The immutable actor and transition record for one committed
  order status change.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: One valid one-time code produces exactly one success across two overlapping
  redemption attempts in all supported environments.
- **SC-002**: One idempotency key and one normalized payload produce one order and one stock
  decrement across any number of retries; a different payload produces zero additional
  mutations and one conflict response.
- **SC-003**: Duplicate or stale cancellation attempts restore each ordered quantity no
  more than once and create no contradictory status history.
- **SC-004**: Every abuse-sensitive public endpoint returns a throttling response at its
  documented limit in automated tests.
- **SC-005**: All required automated checks complete successfully on the hardened revision,
  with zero formatting violations and zero known locked-dependency advisories.
- **SC-006**: A maintainer can identify the runtime, required extensions, queue worker,
  scheduler, storage limitation, and new conflict response from the project documentation
  without inspecting source code.

## Assumptions

- Existing bearer-token authentication, user roles, order workflow, and successful API
  response shapes remain unchanged.
- Current one-time-code time-to-live and per-phone issue limit remain the baseline.
- Conflict is the appropriate response when one idempotency key represents two different
  intended operations.
- Fast local tests continue using the existing lightweight database; a separate
  production-compatible database path will be documented for true locking verification.
- No production deployment or production migration is part of this feature batch.

## Out of Scope

- Replacing the notification system with an outbox architecture.
- Reworking product-image transaction compensation.
- Adding a complete OpenAPI specification or changing API versioning.
- Changing the order workflow, product model, authentication method, or SMS provider.
- Deploying or migrating any production environment.
