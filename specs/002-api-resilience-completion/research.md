# Research: API Resilience Completion

## Product image mutation ordering

**Decision**: Store replacement files before persistence, compensate the new file if the
database mutation fails, and record old-file cleanup in the same database transaction as
the successful update/delete. Attempt cleanup after commit and retain failed work for a
scheduled retry.

**Rationale**: Database state never points at a file deleted before commit. Durable cleanup
turns a failed delete into visible retry work rather than an untracked orphan.

**Alternatives considered**: Deleting first breaks references when persistence fails.
Deleting only after commit protects references but can leave invisible orphans. A general
distributed transaction is unsupported by ordinary filesystems and unnecessary here.

## Notification identity and channel ordering

**Decision**: Derive a deterministic UUID per notification class, recipient, and logical
event; assign it to the notification and deliver the database channel before SMS. Treat a
unique-primary-key conflict as an already delivered no-op.

**Rationale**: The existing notifications table already provides an atomic uniqueness
boundary. When two workers race, only the database winner reaches later channels. This
removes check-then-send races without a second delivery ledger.

**Alternatives considered**: Existing JSON queries race. A separate claim table duplicates
the durable notification record and introduces leases/crash recovery. A full outbox is
appropriate for broader integrations but larger than the three current listeners need.

## Back-in-stock subscription lifecycle

**Decision**: Retain the subscription until notification delivery returns either newly
delivered or already durable, then delete it idempotently.

**Rationale**: Deleting before delivery avoids duplicates but loses the user's request if
database delivery fails. Database-first deterministic delivery makes post-delivery delete
safe under retries and concurrent workers.

**Alternatives considered**: Restoring a deleted subscription after failure races with new
subscriptions. Holding a transaction open during notification channels increases locks and
cannot cover external delivery.

## Real concurrency harness

**Decision**: Launch independent PHP processes through the existing process component.
Each process boots the application, reconnects to the shared database, writes a ready
marker, waits behind a file barrier, performs one operation, and writes strict JSON output.

**Rationale**: Separate processes provide distinct database connections and genuinely
overlap the row-lock window. A bounded file barrier is portable in CI and does not require
another service.

**Alternatives considered**: Sequential stale instances prove reload logic but not locks.
Forking the PHPUnit process inherits unsafe connection/runtime state. Database advisory
locks would make the test depend on a second database-specific synchronization feature.

## Production database coverage

**Decision**: Keep the fast SQLite job and run full MySQL 8 and PostgreSQL 16 jobs, both of
which must execute the production concurrency tests.

**Rationale**: The application claims both database families are supported and their row
locking, transaction abort, UUID, and insert-ignore behavior differ.

**Alternatives considered**: One production database job leaves the other claim unproved.
A matrix is compact but service definitions and health checks are clearer as explicit jobs.

## Listing validation

**Decision**: Add dedicated Form Requests for product and order collection endpoints.
Validate all accepted fields, cross-field price range ordering, enums, sort/direction,
positive pagination, and the administrator-only user filter before query construction.

**Rationale**: Request objects produce the existing standard validation envelope and keep
controllers limited to validated typed inputs.

**Alternatives considered**: Controller casts silently convert bad inputs. Extending the
generic sorting trait cannot express endpoint-specific filters and authorization.
