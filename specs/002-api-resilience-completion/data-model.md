# Data Model: API Resilience Completion

## Pending File Deletion

New table: `pending_file_deletions`

| Field | Type | Constraint | Purpose |
| --- | --- | --- | --- |
| `id` | integer | primary key | Cleanup row identity |
| `disk` | string | unique with `path` | Filesystem disk name |
| `path` | string | unique with `disk` | Unused file to remove |
| `attempts` | unsigned integer | default 0 | Cleanup attempts made |
| `last_error` | text, nullable | none | Latest bounded diagnostic |
| `last_attempted_at` | timestamp, nullable | none | Retry visibility |
| timestamps | timestamp | none | Scheduling/audit metadata |

### Lifecycle

```text
scheduled -> delete attempt -> file absent/deleted -> row removed
scheduled -> delete failure -> attempts incremented + error retained -> scheduled
```

Invariant: `(disk, path)` is unique. Re-scheduling an existing obligation must not create a
second row. Rollback drops only this metadata table and never touches the named files.

## Durable Notification Identity

Existing table: `notifications`; no schema change.

The existing UUID primary key becomes the deduplication identity. It is deterministically
derived from:

```text
notification class + notifiable type + notifiable id + logical event key
```

The database notification insert is the claim. The database channel runs first. A primary
key conflict means another worker already created the durable notification and later
channels must not run.

Logical event keys:

| Notification | Event identity |
| --- | --- |
| New product | product ID |
| Back in stock | stock subscription ID |
| Order status | order status history ID |

## Concurrent Attempt Result

Test-only JSON result written by each child process:

| Field | Values | Meaning |
| --- | --- | --- |
| `outcome` | `created`, `insufficient_stock`, `delivered`, `duplicate`, `error` | Operation result |
| `order_id` | integer/null | Created/replayed order when relevant |
| `message` | string/null | Diagnostic only for error results |

The parent accepts only known outcomes, zero exit status, and results written before the
timeout.

## Listing Query Contracts

### Products

| Field | Accepted value |
| --- | --- |
| `search` | nullable string, maximum 200 characters |
| `min_price` | nullable numeric, at least zero |
| `max_price` | nullable numeric, at least zero and not below `min_price` |
| `in_stock` | nullable boolean |
| `sort` | `price`, `title`, or `created_at` |
| `direction` | `asc` or `desc` |
| `per_page` | integer 1–100 |
| `page` | integer at least 1 |
### Orders

| Field | Accepted value |
| --- | --- |
| `status` | one existing order status value |
| `user_id` | existing user ID, administrators only |
| `sort` | `created_at` or `total` |
| `direction` | `asc` or `desc` |
| `per_page` | integer 1–100 |
| `page` | integer at least 1 |
