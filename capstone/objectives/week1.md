# Week 1 Audit

## Goal

Week 1 focuses on understanding the largest maintenance risks in the codebase, defining a clearer target structure, selecting one pilot module, and fixing obvious text/encoding issues.

## Week 1 Status

- Largest-file audit: Completed
- Target structure defined: Completed
- Pilot module selected: Completed
- Pilot module initial split started: Completed
- Encoding cleanup (visible login issues): Completed

## Largest/Riskiest Files

| File | Approx Size | Type | Risk |
|---|---:|---|---|
| `index.php` | ~170 KB | Mixed dashboard/page logic | Large amount of query logic and rendering in one file makes changes risky and slows debugging. |
| `pages/cashier_view.php` | ~185 KB | UI + workflow logic | Very large page file likely mixes presentation, behavior, and business rules. |
| `pages/rider_view.php` | ~173 KB | UI + workflow logic | Large rider workflow page increases complexity and makes future changes harder to isolate. |
| `pages/inventory.php` | ~74 KB | Page + data logic | Inventory is a critical module and likely to carry hidden business rules. |
| `pages/sales.php` | ~68 KB | Page + workflow logic | Sales is operationally sensitive and should be easier to test and maintain. |
| `pages/orders.php` | ~66 KB | Page + form/process logic | Orders is central to operations and already shows signs of complexity. |
| `api/sales_backend.php` | ~45 KB | Backend/business logic | Large backend endpoint likely mixes validation, DB access, and workflow rules. |
| `api/ar_backend.php` | ~37 KB | Backend/business logic | Accounts receivable logic is business-critical and should be made more modular. |
| `api/orders_backend.php` | previously large, now reduced | Backend entry point | Was a strong candidate because it handled request parsing, validation, logic, and responses together. |

## Why These Files Were Chosen

These files were identified because they are among the largest custom PHP files in the repo and are likely to contain mixed responsibilities such as:

- request handling
- authorization
- validation
- database access
- business rules
- HTML rendering
- inline response handling

This makes them harder to test, harder to reuse, and more likely to break during feature changes.

## Target Structure

Week 1 established the following target structure for future refactors:

- `pages/`: page rendering and UI composition only
- `api/`: request handlers only (auth, csrf, input parsing, dispatch, response)
- `includes/services/`: business logic and workflows
- `includes/repositories/`: database queries and persistence helpers
- `includes/helpers/`: pure reusable helpers with no side effects

## Architecture Rules

To keep the new structure clear, these rules apply:

- `pages/` should not contain major business logic or direct workflow orchestration
- `api/` should only coordinate request/response behavior and delegate real work
- `services/` should own workflows and module-level business rules
- `repositories/` should own reusable database access
- `helpers/` should remain side-effect free and contain pure utility logic where possible

## Pilot Module Selection

The `orders` module was chosen as the first pilot refactor.

### Why `orders` was chosen first

- It is central to day-to-day business operations
- It contains validation, workflow, and persistence concerns
- It is a good template for future cleanup of `sales`, `delivery`, and rider-related modules
- It provides a manageable first step without requiring a full rewrite

## Orders Refactor Pattern Introduced

The following split was introduced for the `orders` module:

- `api/orders_backend.php`
  - Handles auth, role checks, csrf checks, request validation at the transport layer, action routing, and responses
- `includes/services/orders_service.php`
  - Owns order workflows such as create, update status, assign delivery, and cancel
- `includes/repositories/orders_repository.php`
  - Owns reusable DB operations such as table checks, delivery-person lookup, delivery-detail sync, and status history writes
- `includes/helpers/orders_helper.php`
  - Owns stateless validation helpers such as date, time, and coordinate checks

## Encoding Cleanup

Visible text encoding issues previously observed in `login.php` were corrected during Week 1.

This confirms that UI-facing files should be kept in a consistent encoding format moving forward to avoid corrupted labels or display issues.

## Week 1 Deliverables Achieved

- Identified the largest and riskiest custom PHP files
- Defined a target architecture structure for future refactors
- Chose `orders` as the pilot module
- Applied the new split to the `orders` backend
- Fixed visible encoding problems in the login page

## Next Targets After Week 1

These are the recommended next modules/files to refactor using the same pattern:

1. `api/sales_backend.php`
2. `pages/cashier_view.php`
3. `pages/rider_view.php`

## Migration Tracker

| Module | Status | Notes |
|---|---|---|
| Orders | Started | New service/repository/helper split introduced |
| Sales | Next | Strong candidate for Week 2 |
| Delivery | Pending | Should follow after sales/orders pattern is stable |
| Rider | Pending | Likely needs both page and backend cleanup |
| Inventory | Pending | Important but larger and better tackled after the first pattern stabilizes |

## Summary

Week 1 is complete once the project has:

- a recorded audit of the largest risks
- a written target structure
- a chosen pilot module
- an initial refactor pattern applied
- visible encoding cleanup documented

This audit marks those Week 1 goals as completed and provides the starting point for Week 2 work.
