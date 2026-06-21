# ADR 029: Security, Roles & Permissions Governance

## 1. Title
ADR-029: Security, Roles & Permissions Governance

## 2. Status
Proposed

## 3. Context
Over the preceding 28 ADRs, IVORQ has constructed a massive, enterprise-grade financial and operational ecosystem. This system holds the power to move millions of dollars via AP payments, write off vast quantities of inventory, revalue currencies, and publish legally binding Group Financial Statements. In the hospitality industry, staff turnover is extraordinarily high, and insider fraud (e.g., stealing liquor, creating ghost vendors, applying fake cash deposits) is a constant threat. If the keys to this kingdom are not governed by a rigid, heavily audited Security and Access Architecture, the system's mathematical purity will be instantly compromised by malicious actors or well-meaning but incompetent users.

## 4. Problem Statement
Standard Role-Based Access Control (RBAC) (e.g., assigning a user the "Manager" role) fails catastrophically in a multi-property hospitality environment. A Director of Finance at Property A must be physically barred from executing period closes at Property B. Furthermore, Sarbanes-Oxley (SOX) and international auditing standards demand absolute Segregation of Duties (SoD). If the system allows the same user to create a Vendor Profile, generate a Purchase Order, Receive the Goods, and approve the Payment, it is facilitating systemic embezzlement. Without emergency "break-glass" protocols, strict session revocation, and immutable audit logs, external auditors will fail the entire IT control environment.

## 5. Decision
IVORQ will implement a **Dimensionally-Scoped Role-Based Access Control (RBAC) System**. Permissions will not merely be "True/False" booleans; they will be strictly scoped by `Tenant`, `Property`, or `Location`. Absolute Segregation of Duties (SoD) will be hard-coded into the business logic for all critical financial lifecycles. Multi-Factor Authentication (MFA) is mandatory for any user holding financial approval power. Every state-mutating action will generate an immutable, cryptographically secure audit log payload.

## 6. Role Model
Roles in IVORQ are collections of Permissions. Roles do not hold power until they are assigned to a User *within a specific Scope*.
- **System Admin:** Scoped to `Tenant`. Can configure global settings, but explicitly *cannot* execute financial transactions.
- **Group Executive:** Scoped to `Tenant`. Read-only access to all properties for consolidation (ADR-024).
- **Director of Finance:** Scoped to `Property`. Full financial execution power within their specific hotel.
- **Inventory Manager:** Scoped to `Location` (e.g., Main Kitchen). Can execute Production Orders (ADR-020) only for their assigned store.

## 7. Permission Model
Permissions are hyper-granular and follow a `Domain.Resource.Action` syntax (e.g., `finance.period.close`, `inventory.count.approve`, `ap.payment.execute`).
- *Example:* The permission to count inventory (`inventory.count.create`) is entirely distinct from the permission to approve the resulting shrinkage variance (`inventory.count.approve`).

## 8. Approval Rules
*Ref ADR-003 (Approval Engine):*
- Approval matrices must route based on the organizational hierarchy mapped to the Role Model. 
- The system must prevent "Self-Approval." If User A submits a Purchase Order for $50,000, and User A also happens to hold the `po.approve` permission, the system must hard-block them from approving their own document. It must dynamically route to the next authorized peer or superior.

## 9. Segregation of Duties (SoD) Rules
The following combinations of permissions are deemed "Toxic" and the authorization engine will refuse to grant them to the same user simultaneously:
1. `vendor.create` + `ap.payment.execute` (Prevents Ghost Vendor fraud).
2. `inventory.receive` + `ap.invoice.match` (Prevents Receiving/Invoice collusion).
3. `inventory.count.execute` + `inventory.variance.approve` (Prevents theft masking).
4. `gl.journal.create` + `finance.period.close` (Requires oversight on month-end adjustments).

## 10. Emergency Access Rules ("Break-Glass")
- In emergencies (e.g., the sole DoF is hospitalized on month-end close), an IT Administrator can grant a temporary `Emergency Financial Controller` role.
- **Controls:** This role automatically revokes itself after 24 hours. Every single action executed while holding this role triggers a real-time email/SMS alert to the Group CFO and System Audit committee.

## 11. Audit Requirements
- **Immutable Action Logging:** Every `POST`, `PUT`, `PATCH`, and `DELETE` request must be intercepted by middleware and logged to an independent `AuditTrail` database. The log must include: User ULID, IP Address, Timestamp, Action, Resource ID, and a JSON diff of the `Previous_State` vs `New_State`.
- **Session Revocation:** HR offboarding triggers an immediate API call to revoke all active JWTs/Session tokens. Terminated employees must be locked out instantly, mid-session.

## 12. Reporting Requirements
1. **User Access Review (UAR) Matrix:** An exportable grid showing every user, their roles, and their specific property scopes for quarterly auditor sign-off.
2. **SoD Conflict Report:** Systemically highlights any accidental overlap of toxic permissions (e.g., someone created a custom role that accidentally violated SoD).
3. **Break-Glass Audit Log:** Details all actions taken under emergency access.

## 13. Risks
- **The "Admin Override" Vulnerability:** If the System Admin (IT) can arbitrarily reset the password of the Director of Finance, log in as them, wire money to a ghost vendor, and then delete the audit log, the entire control environment is useless. The database holding the `AuditTrail` must be isolated, ideally append-only, and inaccessible even to application-level DBAs.
- **Over-Permissioning via Custom Roles:** If IVORQ allows hotels to create unlimited Custom Roles, lazy IT managers will inevitably create a "Super Manager" role that holds every permission just to stop staff from complaining about access blocked errors, instantly destroying SoD.

## 14. Advantages
- SOX-compliant access controls that will pass Big Four IT General Controls (ITGC) audits without exception.
- Dimensionally-scoped permissions ensure true multi-tenant/multi-property data isolation.
- Protects the hotel against the most common vectors of employee embezzlement.

## 15. Trade-Offs
- Severe operational friction. In a small 50-room boutique hotel, the General Manager often acts as the DoF, the HR manager, and the purchaser. Enforcing strict SoD on a 3-person management team might paralyze their ability to operate the hotel. The system must provide a documented "Small Property Waiver" toggle that relaxes SoD rules while flagging the property as high-audit-risk.

## 16. Consequences
- The authentication mechanism must support centralized Single Sign-On (SSO) via SAML/OIDC (e.g., Azure AD, Okta) to tie IVORQ access directly to the hotel's central HR identity provider.
- The authorization middleware must inject the user's `property_id` scope into *every single database query* to ensure cross-property data leakage is architecturally impossible.
