# Validation Quickstart: API Resilience Completion

## Prerequisites

- PHP 8.3 with the extensions declared by Composer
- Installed Composer dependencies
- SQLite for the fast suite
- Disposable MySQL 8 and PostgreSQL 16 databases for production-locking proof
- Process execution enabled for the specialized concurrency tests

## Fast local validation

```bash
composer validate --strict --no-check-publish
composer audit --locked --no-interaction
vendor/bin/pint --test
php artisan test
php artisan schedule:list
```

Expected: the full fast suite passes. Production concurrency scenarios report an explicit
skip because SQLite does not provide the required row-lock semantics.

## Focused validation

```bash
php artisan test --filter=ProductImageSafetyTest
php artisan test --filter=NotificationDeduplicationTest
php artisan test --filter=ProductListingTest
php artisan test --filter=OrderListingTest
php artisan test --filter=ProductionDatabaseConcurrencyTest
```

Confirm the behaviors in [api-contract.md](contracts/api-contract.md): file compensation
and durable cleanup, one notification identity per recipient/event, strict listing
validation, and unchanged success responses.

## Production database validation

Run the full suite once with each supported production connection. The specialized test
must execute—not skip—and must report one successful order, one insufficient-stock result,
one order row, and zero remaining stock. It also races one logical notification and
expects one durable record.

The CI workflow is the canonical reproducible environment for both database families.

## Migration lifecycle

Against a disposable database containing products and images:

```bash
php artisan migrate
php artisan migrate:rollback --step=1
php artisan migrate
```

Rollback removes only `pending_file_deletions`. It must preserve products, notifications,
subscriptions, and files. Process outstanding cleanup rows before any real rollback.
