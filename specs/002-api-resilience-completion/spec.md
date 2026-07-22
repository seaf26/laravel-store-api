# Feature Specification: API Resilience Completion

**Feature Branch**: `feature/docs`

**Created**: 2026-07-22

**Status**: Approved for planning

**Input**: Complete the remaining reliability audit items: production-database concurrency
proof, failure-safe product images, race-safe notification deduplication, and strict listing
query validation.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Failure-Safe Product Images (Priority: P1)

As an administrator, creating, replacing, or deleting a product never leaves a product
record pointing to a missing image, and failed cleanup work remains discoverable and
retryable rather than becoming an untracked orphan.

**Why this priority**: Product records and public images span two storage systems. A
partial failure can make catalogue data permanently inconsistent.

**Independent Test**: Force persistence and file-deletion failures during create, update,
and delete operations; confirm the catalogue remains usable, newly unused files are
compensated, and deferred cleanup is retained until it succeeds.

**Acceptance Scenarios**:

1. **Given** a new image is stored, **When** product creation fails, **Then** the new file
   is removed immediately or recorded for retry and no product references it.
2. **Given** an existing product image, **When** replacement persistence fails, **Then**
   the product still references the old image and the unused replacement is compensated.
3. **Given** a replacement or deletion commits, **When** the old file cannot be removed,
   **Then** the successful catalogue change remains valid and cleanup is retried later.
4. **Given** pending image cleanup, **When** maintenance succeeds, **Then** both the unused
   file and its pending-cleanup record are removed.

---

### User Story 2 - Race-Safe Notification Delivery (Priority: P1)

As a customer, duplicate or concurrently executed queue jobs do not create duplicate
in-app notifications or duplicate SMS delivery attempts for the same logical event.

**Why this priority**: Check-then-send logic is not atomic; two workers may observe the
same gap and both notify the customer.

**Independent Test**: Execute the same new-product, restock, and order-status delivery
more than once, including overlapping workers on a shared database, and observe one
durable in-app notification and at most one SMS attempt per recipient/event.

**Acceptance Scenarios**:

1. **Given** two workers deliver the same logical notification, **When** they overlap,
   **Then** one durable notification identity wins and the duplicate worker exits cleanly.
2. **Given** a durable notification already exists, **When** its job is retried, **Then**
   neither the in-app notification nor SMS is sent again.
3. **Given** a back-in-stock subscription, **When** delivery fails before becoming
   durable, **Then** the subscription remains available for a later retry.
4. **Given** a back-in-stock notification is durable, **When** the listener completes or
   is retried, **Then** the subscription is consumed without another delivery.

---

### User Story 3 - Production-Database Concurrency Proof (Priority: P1)

As a maintainer, the automated suite proves order locking with truly overlapping
independent processes against each supported production database family rather than
inferring safety from sequential lightweight-database tests.

**Why this priority**: Row-lock behavior is database-specific and is central to stock
integrity.

**Independent Test**: Start two independent order attempts behind a shared barrier for a
single remaining unit; exactly one succeeds, one fails for insufficient stock, one order
exists, and stock is zero on each production-compatible database job.

**Acceptance Scenarios**:

1. **Given** one unit and two eligible buyers, **When** their independent order processes
   are released together, **Then** exactly one order succeeds and stock never becomes
   negative.
2. **Given** a production-database CI job, **When** the full suite runs, **Then** the real
   overlap test executes rather than being reported as skipped.
3. **Given** a lightweight local database or unavailable process support, **When** the
   suite runs, **Then** the specialized test skips with an explicit environmental reason
   while the fast suite remains green.

---

### User Story 4 - Predictable Listing Validation (Priority: P2)

As an API consumer, malformed product and order listing filters are rejected consistently
instead of being silently cast, clamped, or ignored.

**Why this priority**: Predictable validation makes client errors visible and prevents
ambiguous queries, but it follows data-integrity work.

**Independent Test**: Submit invalid price ranges, booleans, statuses, user identifiers,
sort fields, directions, page numbers, and page sizes; each receives a field-addressable
validation response while valid queries retain their current results.

**Acceptance Scenarios**:

1. **Given** malformed product filters or pagination, **When** the list is requested,
   **Then** the response is `422` with errors for the offending fields.
2. **Given** an inverted product price range, **When** the list is requested, **Then** the
   maximum-price field is rejected.
3. **Given** an unknown order status, missing user identifier, or malformed pagination,
   **When** an order list is requested, **Then** the response is `422`.
4. **Given** valid existing filters, sorting, and pagination, **When** either list is
   requested, **Then** the existing response and authorization behavior is unchanged.

### Edge Cases

- A storage adapter may return failure without throwing; this must still leave durable
  cleanup work rather than silently abandoning a file.
- A cleanup request may name a file already removed by an earlier attempt; that is a
  successful idempotent cleanup.
- A product may be updated without a new image; no cleanup work should be created.
- A notification duplicate may arrive after the first worker committed the in-app record
  but before that worker completed its remaining channels.
- A notification failure before durable delivery must remain retryable; a duplicate-key
  conflict must not fail the queue job.
- Process startup or barrier failure must fail the concurrency test rather than producing
  a false pass.
- Query values such as `per_page=0`, `page=-1`, non-boolean stock flags, `NaN` prices, and
  unknown enum values must not be coerced into valid queries.
- A regular customer cannot use order-list filters to escape ownership scoping.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Failed product creation after file storage MUST leave no product reference
  and MUST compensate or durably record the unused file for retry.
- **FR-002**: Failed product image replacement MUST preserve the original product/image
  state and compensate or record the unused replacement.
- **FR-003**: Product update and deletion MUST commit catalogue changes before removing an
  old image, so persistence failure cannot leave a record referencing a removed file.
- **FR-004**: Failed old-image deletion MUST create or preserve idempotent pending cleanup
  work that scheduled maintenance can retry.
- **FR-005**: Every logical customer notification MUST have a deterministic,
  recipient-scoped durable identity.
- **FR-006**: Concurrent delivery of the same logical notification MUST create at most one
  durable in-app record and at most one downstream SMS attempt.
- **FR-007**: A duplicate durable-notification conflict MUST be treated as a successful
  no-op, while failures before durable delivery MUST remain retryable.
- **FR-008**: A back-in-stock subscription MUST remain until its durable notification is
  created and MUST be consumed after delivery or confirmed duplicate delivery.
- **FR-009**: The concurrency acceptance test MUST launch at least two independent
  processes, synchronize their start, and use a shared production-compatible database.
- **FR-010**: Automated review MUST execute the overlap test against both supported
  production database families.
- **FR-011**: Environments that cannot provide production locking or independent process
  execution MUST skip only the specialized concurrency scenario with an explicit reason.
- **FR-012**: Product-list queries MUST validate search, minimum/maximum price, stock flag,
  sorting, direction, page size, and page number before query construction.
- **FR-013**: Order-list queries MUST validate status, administrator user filter, sorting,
  direction, page size, and page number before query construction.
- **FR-014**: Invalid listing input MUST return the standard `422` validation envelope
  with field-specific errors and MUST NOT silently clamp or cast invalid values.
- **FR-015**: Valid listing filters, pagination, authorization, and response shapes MUST
  remain backward compatible.
- **FR-016**: Maintainer and API documentation MUST describe cleanup maintenance,
  notification deduplication boundaries, validated listing parameters, and the exact
  concurrency-test environments.

### Reliability Requirements

- **RR-001**: Cross-storage compensation and pending cleanup MUST be idempotent.
- **RR-002**: No product record may reference a file removed before its database mutation
  commits.
- **RR-003**: Notification deduplication MUST rely on a database-enforced unique identity,
  not a check followed by an unprotected send.
- **RR-004**: The in-app database channel MUST become durable before best-effort external
  channels run.
- **RR-005**: The overlap test MUST fail on timeout, malformed child output, or unexpected
  process exit.
- **RR-006**: New migrations MUST preserve existing products, images, notifications, and
  subscriptions and MUST have data-preserving rollback behavior.

### Key Entities

- **Pending Image Cleanup**: A unique disk/path cleanup obligation retained until the
  unused file is absent.
- **Logical Notification Identity**: A stable recipient/event key represented by the
  durable in-app notification identifier.
- **Concurrent Attempt Result**: One independent process outcome used to prove the final
  order and stock invariant.
- **Listing Query Contract**: The accepted typed filters, sorting, and pagination fields
  for one collection endpoint.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: All forced product persistence and storage-failure scenarios leave zero
  broken image references and zero untracked unused files.
- **SC-002**: Ten repeated deliveries of one logical notification produce exactly one
  in-app record and no more than one SMS attempt for the recipient.
- **SC-003**: Two overlapping buyers for one remaining unit produce exactly one order, one
  insufficient-stock result, and final stock of zero in every supported production
  locking environment.
- **SC-004**: Every documented invalid listing parameter returns `422` with its field name
  present in the error object.
- **SC-005**: All previously valid listing, product-management, notification, and ordering
  scenarios continue to pass without response-shape changes.
- **SC-006**: A maintainer can identify the retry mechanisms, deduplication boundary,
  supported query values, and commands that prove real concurrency from documentation
  without inspecting source code.

## Assumptions

- The database-backed in-app notification is the durable delivery boundary. SMS remains
  best-effort and runs only after that boundary succeeds.
- File deletion may complete asynchronously as long as pending work is durable and the
  catalogue never references a prematurely removed file.
- Existing success responses and permissions remain unchanged.
- The CI environment can run independent command-line processes for production-database
  concurrency scenarios.

## Out of Scope

- Exactly-once guarantees from the external SMS provider after a host crash.
- Moving product media to a new storage provider or changing public image URLs.
- Replacing the queue system or introducing a general-purpose event-sourcing platform.
- Adding new listing filters or changing collection response formats.
- Production deployment or production migration execution.
