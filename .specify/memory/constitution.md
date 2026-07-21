<!--
Sync Impact Report
- Version change: template -> 1.0.0
- Added principles:
  - I. Security at Trust Boundaries
  - II. Transactional Integrity and Concurrency
  - III. Tests Prove Behavior
  - IV. API Contracts Stay Synchronized
  - V. Framework-Native Simplicity
- Added sections:
  - Technical and Operational Constraints
  - Delivery and Review Gates
- Removed sections: none; template placeholders were replaced.
- Templates:
  - ✅ .specify/templates/plan-template.md
  - ✅ .specify/templates/spec-template.md
  - ✅ .specify/templates/tasks-template.md
- Runtime guidance reviewed:
  - ✅ README.md
  - ✅ workspace STANDARDS.md and AGENTS.md
- Deferred items: none.
-->
# Store API Constitution

## Core Principles

### I. Security at Trust Boundaries
Every public input MUST be validated before it reaches domain logic. Authentication,
credential recovery, OTP, and other abuse-sensitive endpoints MUST have explicit rate
limits and must not reveal account existence through response content. Secrets and OTP
values MUST NOT appear in API responses or tracked files. Authorization MUST be enforced
through Laravel policies or middleware, with cross-user resource probing returning the
project's documented response. Security controls require negative-path tests.

Rationale: this API accepts credentials, bearer tokens, phone numbers, and administrative
actions; its public boundary is the primary attack surface.

### II. Transactional Integrity and Concurrency
Multi-record state changes MUST be atomic. Invariants such as non-negative stock,
single-use OTP redemption, legal order transitions, idempotent order creation, and
single-delivery side effects MUST be protected by database constraints, conditional
writes, or row locks rather than process-local assumptions. Any migration MUST document
locking, rollback, and existing-data behavior. Concurrency claims MUST be verified on a
database engine that implements the production locking semantics.

Rationale: sequential happy-path tests cannot prove behavior when requests or queue
workers overlap.

### III. Tests Prove Behavior
Behavior changes MUST begin with a focused test that fails for the missing behavior, then
pass after implementation. Tests MUST cover success, authorization, validation, failure,
retry, and concurrency boundaries in proportion to risk. A test name MUST describe what
the test actually exercises; a sequential test MUST NOT claim to prove a race. The full
test suite, formatter, dependency validation, and configured static analysis MUST pass
before work is accepted.

Rationale: the suite is the executable contract for API clients and the safest guard
against regressions in transactions and authentication.

### IV. API Contracts Stay Synchronized
Public status codes, headers, error shapes, pagination, and idempotency behavior MUST be
documented and tested. README endpoint summaries, diagrams, Postman/OpenAPI artifacts,
and environment requirements MUST match the running application. A behavior change is
incomplete until its public contract and examples are updated in the same revision.

Rationale: stale examples are operational defects for API consumers, not merely editorial
issues.

### V. Framework-Native Simplicity
Use Laravel-native validation, policies, queues, events, transactions, rate limiters, and
Eloquent patterns before adding dependencies or custom abstractions. New services MUST
own a real transaction, external side effect, or reusable domain operation. Repository
layers, broad rewrites, and speculative infrastructure are prohibited without a measured
need. Changes MUST be the smallest correct solution that preserves existing behavior.

Rationale: the current application is compact; unnecessary layers would hide rather than
clarify its important invariants.

## Technical and Operational Constraints

- Runtime support is PHP 8.3 or newer, Laravel 13, and every directly used PHP extension
  MUST be declared in Composer and provisioned in CI.
- SQLite is acceptable for fast local and feature tests. Locking and database-portability
  claims MUST also be exercised against the intended production database.
- Queued side effects MUST run after commit where they depend on committed data. Retry and
  duplicate-delivery behavior MUST be explicit.
- Product files and other external storage changes MUST have compensation or ordering that
  prevents database rows from referencing missing files after failure.
- Production delivery is Git-only: commit, push, and update through Git. Production
  actions and migrations require the owner's direct approval and a verified rollback or
  forward-fix path.
- Existing uncommitted user changes MUST be preserved; generated or task-owned changes
  MUST remain distinguishable in review.

## Delivery and Review Gates

1. Non-trivial behavior work follows the lean Spec Kit flow: specification, plan, tasks,
   implementation, then verification.
2. Plans MUST identify security boundaries, state invariants, migration safety, failure
   handling, and the commands that prove completion.
3. Tasks MUST include tests for every behavior change and documentation for every public
   contract change.
4. Before acceptance, run PHPUnit, Pint in check mode, Composer validation and audit, PHP
   syntax checks, and configured static analysis. Database-specific checks are required
   when locking or portability is in scope.
5. A fresh verification pass MUST review the diff and execute the critical user stories.
   Known limitations or unavailable checks MUST be reported; failing required checks
   cannot be described as complete.

## Governance

This constitution governs feature specifications, implementation plans, task lists, code
reviews, and delivery decisions in this repository. Amendments require an explicit change
to this file, a Sync Impact Report, semantic versioning, and owner approval when they alter
security, production, or authorization gates. MAJOR versions remove or redefine a core
principle, MINOR versions add or materially expand a principle or mandatory gate, and
PATCH versions clarify wording without changing obligations. Every feature plan and final
review MUST check compliance; exceptions must be written in the plan's Complexity
Tracking section with a concrete reason and rejected simpler alternative.

**Version**: 1.0.0 | **Ratified**: 2026-07-22 | **Last Amended**: 2026-07-22
