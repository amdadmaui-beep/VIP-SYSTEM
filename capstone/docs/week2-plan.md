# Week 2 Plan

## Goal

Week 2 focuses on refactoring one module end-to-end using the structure defined in Week 1.

The main objective is to make the new architecture real, repeatable, and useful by applying it to a business-critical module without breaking current behavior.

## Primary Focus

Week 2 is centered on the `orders` module.

This module was selected in Week 1 as the pilot refactor because it includes:

- request handling
- validation
- business workflows
- database operations
- redirects / response handling

That makes it the best place to establish a repeatable refactor pattern for the rest of the system.

## Week 2 Success Criteria

Week 2 is considered complete when:

- `api/orders_backend.php` acts mainly as a transport/controller layer
- order business logic lives in `includes/services/orders_service.php`
- reusable database logic lives in `includes/repositories/orders_repository.php`
- stateless helper logic lives in `includes/helpers/orders_helper.php`
- duplicated validation logic is reduced
- the current user-facing behavior of the orders flow still works
- the refactor pattern is documented clearly enough to reuse for `sales` and `delivery`

## Target Files

### Main module files

- `api/orders_backend.php`
- `includes/services/orders_service.php`
- `includes/repositories/orders_repository.php`
- `includes/helpers/orders_helper.php`

### Related UI / integration files

- `pages/orders.php`
- any shared include or helper directly used by the orders flow

## Week 2 Tasks

### 1. Finish transport-layer cleanup in `api/orders_backend.php`

`api/orders_backend.php` should only be responsible for:

- auth checks
- role checks
- csrf checks
- request method enforcement
- action routing
- response or redirect handoff

It should not keep accumulating heavy business logic or large inline validation blocks.

### 2. Move workflow logic into `orders_service.php`

The service layer should own module workflows such as:

- create order
- update order status
- assign delivery
- cancel order

The service should coordinate the sequence of operations and keep the business rules in one place.

### 3. Keep database operations inside `orders_repository.php`

The repository layer should own reusable persistence logic such as:

- checking whether tables or columns exist
- resolving rider or delivery-person records
- creating or syncing related delivery detail rows
- writing order status history
- reusable order lookup queries

This keeps raw SQL from spreading back into page and controller files.

### 4. Centralize stateless validation in `orders_helper.php`

Helpers should contain pure logic that does not depend on redirects or session output, such as:

- date validation
- time validation
- coordinate validation
- reusable small normalization helpers

If additional validations are repeated during the refactor, they should be extracted here when practical.

### 5. Review `pages/orders.php` for mixed responsibilities

Check whether the orders page still contains:

- business logic
- duplicated validation
- heavy data-processing logic
- direct workflow orchestration

If present, move those concerns toward services or repositories and keep the page focused on rendering and UI flow.

### 6. Preserve current behavior while refactoring

Refactoring should not change the intended behavior of:

- order creation
- validation errors
- redirects
- order status changes
- delivery assignment
- cancellation flow

Any behavior changes should be intentional and documented.

### 7. Prepare the pattern for reuse

At the end of Week 2, the `orders` split should be stable enough to reuse for:

1. `api/sales_backend.php`
2. delivery-related backend flows
3. rider-related backend flows

## Recommended Validation Checklist

Use this checklist after Week 2 changes:

- creating a valid order works
- invalid customer input is rejected
- invalid product input is rejected
- discontinued products are rejected
- invalid quantity is rejected
- invalid csrf token is rejected
- assign-delivery flow still works
- update-status flow still works
- cancel-order flow still works
- error redirects still show meaningful feedback

## Week 2 Deliverables

By the end of Week 2, the repo should have:

- a cleaner `orders` backend entry point
- a usable service/repository/helper split for one real module
- reduced duplication in the orders flow
- clearer separation between transport logic and business logic
- a repeatable pattern for future module cleanup

## Risks To Watch

- moving logic without preserving redirects or response behavior
- duplicating SQL across service and repository layers
- leaving partial validation in multiple places
- adding new structure without actually reducing complexity
- refactoring too broadly before the orders pattern is stable

## Recommended Next Step After Week 2

Once the `orders` module is stable, the next module to refactor should be:

1. `api/sales_backend.php`

After that, the next highest-value cleanup targets are:

1. `pages/cashier_view.php`
2. `pages/rider_view.php`

## Summary

Week 2 is about proving that the new structure works in practice.

If Week 1 defined the architecture direction, Week 2 should make that direction concrete by turning the `orders` module into the first clean example of the new pattern.
