# Reliability Architecture

This document is the authoritative architecture view for the API reliability controls.
The [Postman collection](postman_collection.json) contains runnable HTTP examples, while
the [feature contract](../specs/001-api-reliability-hardening/contracts/api-contract.md)
defines the exact changed status codes, headers, and error bodies.

## Public behavior map

| Concern | Boundary | Control | Public result |
| --- | --- | --- | --- |
| Login | `POST /api/auth/login` | Five attempts per minute for both the client source and normalized phone | `429` with `Retry-After` when either bucket is exhausted |
| OTP issue | Registration, verification request, and forgot-password | Three attempts per ten minutes per phone/purpose and 20 per minute per client source | Stable `429`; public recovery/request responses do not reveal account existence |
| OTP redemption | Verification and password reset | Five attempts per minute per source and phone/purpose plus an atomic claim | One code can produce at most one successful action |
| Order retry | `POST /api/orders` with `Idempotency-Key` | Canonical SHA-256 request fingerprint stored with the key | Matching payload replays with `200`; different payload returns `409` |
| Status mutation | `PATCH /api/orders/{order}/status` | Reload and row-lock the order inside the transaction | Stale requests use committed state; duplicate status is a no-op |
| OTP retention | Scheduler | Daily `model:prune` | Consumed or expired OTP rows older than one day are removed |

## Reliability data model

Only the relationships involved in order replay are shown here. The important hardening
change is the `request_hash` value on every application-created idempotency record.

```mermaid
erDiagram
    USERS ||--o{ ORDERS : places
    USERS ||--o{ IDEMPOTENCY_KEYS : owns
    ORDERS ||--|{ ORDER_ITEMS : contains
    ORDERS ||--o| IDEMPOTENCY_KEYS : replayed_by
    PRODUCTS ||--o{ ORDER_ITEMS : snapshots

    IDEMPOTENCY_KEYS {
        bigint id PK
        bigint user_id FK
        string key "max 64 at API boundary"
        string request_hash "nullable SHA-256; legacy rows backfilled"
        bigint order_id FK
    }

    ORDER_ITEMS {
        bigint id PK
        bigint order_id FK
        bigint product_id FK
        integer quantity
        decimal unit_price
    }
```

The unique identity is `(user_id, key)`. A fingerprint is calculated by aggregating
quantities by integer product ID, sorting by product ID, serializing fixed-key rows, and
hashing the result with SHA-256. Reordering lines or splitting one product across several
lines therefore does not change the fingerprint.

## OTP issue and redemption

```mermaid
sequenceDiagram
    participant C as Client
    participant R as Named rate limiter
    participant O as OtpService
    participant L as Shared cache lock
    participant D as Database
    participant S as SMS sender

    C->>R: Request or redeem OTP
    alt bucket exhausted
        R-->>C: 429 + Retry-After
    else allowed issue
        R->>O: issue(phone, purpose)
        O->>L: acquire HMAC(phone + purpose) lock
        L->>D: transaction: check issue count
        D->>D: consume earlier usable codes
        D->>D: insert bcrypt-hashed replacement
        D-->>L: commit
        L-->>O: release
        O->>S: deliver code after commit
        O-->>C: stable public response
    else allowed redemption
        R->>O: verify(phone, purpose, code)
        O->>D: load latest usable code
        O->>O: verify bcrypt hash
        O->>D: conditional claim where unused and unexpired
        alt exactly one row claimed
            O-->>C: success
        else already claimed, replaced, or expired
            O-->>C: invalid or expired code
        end
    end
```

Limiter and lock keys use keyed HMAC derivation; raw phone numbers are not stored in those
keys. SMS delivery occurs after the issuance transaction and lock have completed, so a
rolled-back code is never sent.

## Order placement and idempotency

```mermaid
sequenceDiagram
    participant C as Client
    participant A as OrderController
    participant O as OrderService
    participant D as Database

    C->>A: POST /api/orders + Idempotency-Key
    A->>O: place(user, items, key)
    O->>O: normalize items and calculate request hash
    O->>D: find (user_id, key)

    alt key already exists and hashes match
        D-->>O: original order
        O-->>A: replayed result
        A-->>C: 200 + Idempotency-Replayed: true
    else key already exists and hashes differ
        O-->>A: IdempotencyConflictException
        A-->>C: 409, no order or stock mutation
    else unused key
        O->>D: begin transaction
        O->>D: lock product rows in stable ID order
        alt any quantity unavailable
            O->>D: roll back
            O-->>A: insufficient stock
            A-->>C: 422, no partial mutation
        else stock available
            O->>D: create order and items; decrement stock
            O->>D: store key + request_hash + order_id
            O->>D: commit
            O-->>A: created result
            A-->>C: 201
        end
    end
```

The request hash represents the client's normalized order intent, not mutable prices,
stock, order status, or the response body. Keys longer than 64 characters are rejected
with the standard `422` validation envelope.

## Order status mutation

```mermaid
sequenceDiagram
    participant A as Admin client
    participant C as OrderController
    participant S as OrderStatusService
    participant D as Database
    participant Q as Queued listener

    A->>C: PATCH /api/orders/{id}/status
    C->>S: change(route-bound order, target, actor)
    S->>D: begin transaction
    S->>D: reload order by ID FOR UPDATE
    alt target equals committed status
        S->>D: commit without history or stock change
        S-->>C: unchanged
        C-->>A: 200 Status unchanged
    else transition is illegal from committed status
        S->>D: roll back
        S-->>C: invalid transition
        C-->>A: 422
    else valid transition
        opt target is cancelled
            S->>D: lock products and restock once
        end
        S->>D: update status and append history
        S->>D: commit
        S->>Q: dispatch status event after commit
        S-->>C: changed order
        C-->>A: 200
    end
```

## Verification and limits

Run the local acceptance gate with:

```bash
composer validate --strict --no-check-publish
composer audit --locked --no-interaction
vendor/bin/pint --test
composer test
php artisan schedule:list
```

GitHub Actions repeats the suite on SQLite and MySQL 8. SQLite is the fast local path;
the MySQL job verifies migration and SQL compatibility. The current tests exercise stale
models and compare-and-set loss, but do not launch two truly simultaneous request
processes. PostgreSQL and the hosted GitHub runner must not be described as locally
verified until those checks have actually run.
