# Validation Quickstart: API Reliability Hardening

## Prerequisites

- PHP 8.3+ with the extensions declared by Composer, including BCMath
- Composer dependencies installed
- Application key configured
- SQLite for the fast suite; MySQL for the production-compatible CI path

## Fast local validation

```bash
composer validate --strict --no-check-publish
composer audit --locked --no-interaction
vendor/bin/pint --test
composer test
php artisan schedule:list
```

Expected outcomes:

- Composer metadata is valid and the locked dependency set has no advisories.
- Pint reports no changed files.
- The full suite passes.
- The schedule lists daily model pruning.

## Focused behavior validation

```bash
php artisan test --filter=LoginTest
php artisan test --filter=PhoneVerificationTest
php artisan test --filter=PasswordResetTest
php artisan test --filter=OrderIdempotencyTest
php artisan test --filter=OrderStatusTest
```

Confirm the scenarios in [api-contract.md](contracts/api-contract.md): throttling responses
carry retry headers, an OTP succeeds only once, matching order retries replay, mismatched
retries conflict without mutation, stale status changes use committed state, and duplicate
cancellation does not double-restock.

## Migration validation

Against a disposable database with at least one existing idempotency row:

```bash
php artisan migrate
php artisan migrate:rollback --step=1
php artisan migrate
```

After the first/third command, existing keys have a 64-character request hash and still
replay. Rollback removes only the new column and preserves orders, items, stock, and keys.

## Production-compatible database path

The CI MySQL job runs the complete suite with environment database settings overriding the
SQLite defaults. It verifies migration and SQL compatibility. SQLite tests exercise stale
model behavior but do not prove `FOR UPDATE` locking; do not describe the sequential local
test as a concurrency proof.
