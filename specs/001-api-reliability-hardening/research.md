# Research: API Reliability Hardening

## Named authentication limiters

**Decision**: Register named limiter groups in the existing application provider. Each
group returns independent client-source and HMAC-phone limits. Use the standard throttling
middleware so `429`, `Retry-After`, and rate-limit headers are generated consistently;
provide a shared JSON response callback that preserves those headers.

**Rationale**: Named limiters run before controller account lookup, so known and unknown
phones share the same public behavior. HMAC protects low-entropy phone values even if a
limiter key is inspected.

**Alternatives considered**: Controller-local counters duplicate framework behavior and
run too late. A plain phone hash is susceptible to offline enumeration. One combined
source+phone bucket fails to cap distributed attacks against one phone.

## OTP issuance serialization

**Decision**: Use a short distributed cache lock keyed by HMAC(phone + purpose). Inside
the lock, run the database issue-count check, old-code invalidation, and new-code insert in
one retryable transaction. Deliver SMS after the transaction and lock complete.

**Rationale**: The configured database cache already supports atomic locks. This works
across application processes and prevents simultaneous requests from both passing the
issue count.

**Alternatives considered**: Locking existing OTP rows cannot protect the first insert.
Locking a user row couples the OTP service to the user table. Sending SMS inside the
transaction can leak a code for rolled-back data.

## OTP redemption claim

**Decision**: After checking the latest usable code hash, perform a conditional update
requiring the row to remain unconsumed and unexpired; succeed only when exactly one row is
updated.

**Rationale**: This compare-and-set is a single database operation and guarantees at most
one successful claimant without holding a transaction open during password hashing.

**Alternatives considered**: Model `save()` after a read permits double success. A long
row-lock transaction around hash verification increases contention.

## Order status locking

**Decision**: Start the transaction first, reload the order by primary key with a write
lock, and perform no-op/transition checks, optional restock, status update, and history
creation against that instance. Permit transaction retry on deadlock.

**Rationale**: Route-model instances can be stale. MySQL/PostgreSQL translate the lock to
`FOR UPDATE`; SQLite safely exercises stale-instance behavior but does not prove the lock.

**Alternatives considered**: Optimistic version columns would require a broader public
conflict contract. Validating before the transaction preserves the current race.

## Idempotency fingerprint

**Decision**: Integer-cast and aggregate quantities by product ID, numeric-sort product
IDs, serialize a zero-indexed fixed-key list, and SHA-256 hash it. Store the 64-character
hash with every key. Reordered identical lines match; changed products or quantities do
not.

**Rationale**: The normalized representation is deterministic and mirrors the stock
reservation input while excluding mutable prices and statuses.

**Alternatives considered**: Raw request JSON is ordering/format sensitive. Hashing the
created order response includes irrelevant server state. Comparing order items on every
replay costs more and leaves the original intent implicit.

## Request-hash migration

**Decision**: Add a nullable string column, backfill only null rows in ID chunks using the
recorded order items and a migration-local canonicalizer, then leave the column nullable
at database level while enforcing hashes on all new writes.

**Rationale**: This preserves legacy data and avoids a heavier cross-database `NOT NULL`
alter. A migration-local algorithm cannot drift when application code evolves.

**Alternatives considered**: Immediate non-null alteration is supported but can rebuild
SQLite tables or take a heavier MySQL metadata/table lock. Calling application services
from an old migration risks future incompatibility.

## Conflict response

**Decision**: Use the existing renderable domain-exception pattern for a stable JSON 409
response when one key is reused for different normalized items.

**Rationale**: It matches existing stock/status exception handling and keeps controllers
thin.

**Alternatives considered**: Returning validation 422 describes malformed input, not a
conflict with previously accepted state. Controller branching duplicates domain logic.
