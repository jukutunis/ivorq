# API Security Review

## Scope
Review of API boundaries, route definitions, middleware, and request validation.

- Rate Limiting
- Mass Assignment
- Validation (FormRequests)
- Authorization (Policies)
- CSRF & XSS Mitigation
- API Consistency

## Findings

### 1. Rate Limiting (Missing)
- **Status**: **FAIL (Medium Finding)**
- **Analysis**: A global search across `routes/api.php`, `bootstrap/app.php`, `AppServiceProvider`, and Module routes reveals that the `throttle` middleware or custom `RateLimiter` definitions are completely missing.
- **Risk**: API endpoints (including authentication endpoints) are susceptible to brute-force and DoS attacks.

### 2. Mass Assignment
- **Status**: Excellent.
- **Analysis**: Models consistently use strict `$fillable` arrays (e.g., `InventoryItem`, `WorkOrder`). Sensitive models like `AuditLog` use `$guarded = ['*']` preventing all mass assignment globally, forcing direct property mutation. 

### 3. Validation & Type Safety
- **Status**: Good.
- **Analysis**: Requests are routed through strict FormRequests (e.g., `StoreWorkOrderRequest`, `UpdateReceiptRequest`). Validation rules explicitly typecast data. Enums (e.g., `TransactionTypeEnum`, `WorkOrderStatusEnum`) are widely used to prevent invalid state transitions.

### 4. Authorization & Context
- **Status**: Excellent.
- **Analysis**: Spatie Permission is configured cleanly. `SetPermissionTeamIdMiddleware` (`permission.team`) is correctly mapped as an alias and injected into the API pipeline *after* Sanctum auth. This guarantees that role/permission checks are scoped strictly to the current tenant (`property_id`), a critical security pillar. Furthermore, module controllers actively invoke Policies (`Gate::authorize()`).

### 5. CSRF & XSS
- **Status**: Good.
- **Analysis**: As an Inertia/React application, XSS is mitigated by React's default escaping. CSRF tokens are handled natively via the web middleware group for stateful endpoints. Token-based stateless API endpoints are correctly using `auth:sanctum`.

## Conclusion
The API boundary is well-protected against standard mass assignment and authorization bypasses. However, it lacks fundamental API traffic control.

**Status**: Minor Hardening Required (Implement Rate Limiting).
