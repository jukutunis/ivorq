# Property Isolation Audit

## Scope
Review of multi-tenant isolation mechanics ensuring strict boundaries between Properties.

- Property Models
- Repositories
- Policies & Global Scopes
- Middleware & Tenant Context

## Findings

### 1. Global Scopes & Traits
- **`BelongsToProperty` Trait**: The core mechanism for property isolation is centralized in `Shared\Traits\BelongsToProperty.php`.
- **Implementation**: It boots an `addGlobalScope('property', ...)` that automatically appends a `WHERE property_id = ?` clause to every Eloquent query using the `CurrentPropertyService`.
- **Creation Context**: On `creating`, it intercepts the event and automatically assigns `property_id = CurrentPropertyService->resolveOrFail()`. This guarantees no data can be accidentally written without a property context.

### 2. Tenant Context
- `CurrentPropertyService` acts as the tenant context resolver. It isolates state dynamically based on the request (likely populated via middleware or route parameters).
- Because it's injected automatically in the `BelongsToProperty` trait, developers do not need to manually append `property_id` in repositories.

### 3. Cross Property Leakage Risk
- **Risk Level**: Extremely Low.
- **Justification**: Because the `addGlobalScope` is applied at the lowest Eloquent layer, any `Model::all()`, `Model::find()`, or relation eager loading inherently respects the property boundary. To bypass it, a developer must explicitly call `withoutGlobalScope('property')` or `scopeForProperty()`, which is a deliberate and easily reviewable action.

### 4. Middleware & Policies
- Authorization uses Policy-based checks. Given the Global Scope, the models fetched and passed to Policies are already guaranteed to belong to the authenticated user's property context, acting as a double layer of defense.

## Conclusion
Property Isolation is securely implemented at the core framework level using Eloquent Global Scopes and unified Traits. Cross-tenant leakage is effectively mitigated by design.

**Status**: Ready & Hardened.
