# Data Model: API Reliability Hardening

## Idempotency Record

Existing table: `idempotency_keys`

| Field | Type | Constraint | Change |
|---|---|---|---|
| `id` | integer | primary key | Existing |
| `user_id` | integer | foreign key, part of unique key | Existing |
| `key` | string | unique with `user_id`, max 64 at API boundary | Existing |
| `request_hash` | string(64), nullable | SHA-256 lowercase hex | New/backfilled |
| `order_id` | integer | foreign key | Existing |
| `created_at` | timestamp | nullable | Existing |

### Fingerprint invariant

For every application-created idempotency record, `request_hash` represents the normalized
requested product IDs and quantities. Two equivalent item sets MUST produce the same hash
regardless of line order. A key can replay only when its stored hash matches.

### Migration lifecycle

1. Add nullable `request_hash` if it is absent.
2. Read null records in bounded ID chunks.
3. Load their order items, numeric-sort by product, and compute the frozen canonical hash.
4. Update each record.
5. Application writes require a hash; the database remains nullable for low-risk rollback
   and legacy portability.
6. Rollback drops only `request_hash`.

## One-Time Code

No schema change.

State transitions:

```text
issued/unconsumed -> consumed by successful atomic claim
issued/unconsumed -> consumed when replacement is issued
issued/unconsumed -> expired by time
consumed or expired -> pruned after retention window
```

Invariant: only one conditional update from unconsumed to consumed may report success.

## Rate-Limit Bucket

No domain table change. Buckets live in the configured shared limiter/cache store.

| Component | Key material | Window | Maximum |
|---|---|---:|---:|
| Login source | HMAC(client source) | 1 minute | 5 |
| Login phone | HMAC(normalized phone) | 1 minute | 5 |
| OTP issue phone/purpose | HMAC(phone + purpose) | 10 minutes | 3 |
| OTP issue source | HMAC(client source + operation) | 1 minute | 20 |
| OTP attempt source | HMAC(client source + operation) | 1 minute | 5 |
| OTP attempt phone/purpose | HMAC(phone + purpose) | 1 minute | 5 |

## Order and Status History

No schema change. The transaction state machine is strengthened:

```text
begin transaction
  -> lock and reload order
  -> compare target with current committed status
  -> validate transition
  -> optionally lock products and restock once
  -> update order status
  -> append one history record
commit
  -> dispatch notification event
```

A duplicate target equal to the committed status is a no-op with no history or stock
movement.
