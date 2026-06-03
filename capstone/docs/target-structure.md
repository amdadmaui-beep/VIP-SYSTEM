# Target Structure

This structure separates rendering, transport, domain logic, and persistence concerns:

- `pages/`: page rendering and UI composition only
- `api/`: request handlers only (input/auth/csrf/dispatch/response)
- `includes/services/`: business logic and workflows
- `includes/repositories/`: database queries and persistence helpers
- `includes/helpers/`: pure reusable helpers with no side effects

## Orders Module Pattern

Current migration pattern for orders:

- `api/orders_backend.php`
  - Handles auth/role checks, CSRF checks, action routing.
  - Delegates to service functions.
- `includes/services/orders_service.php`
  - Owns order workflows (`create`, `update status`, `assign delivery`, `cancel`).
- `includes/repositories/orders_repository.php`
  - Owns reusable DB operations (table/column checks, rider resolution, delivery-detail sync, status history writes).
- `includes/helpers/orders_helper.php`
  - Owns stateless validation helpers (date/time/coordinate checks).

Apply this same split to `sales`, `delivery`, and `rider` backends next to reduce large-file complexity.
