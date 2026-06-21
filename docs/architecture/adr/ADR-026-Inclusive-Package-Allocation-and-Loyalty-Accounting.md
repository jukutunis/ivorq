# ADR 026: Inclusive Package Allocation & Loyalty Accounting

## 1. Title
ADR-026: Inclusive Package Allocation & Loyalty Accounting

## 2. Status
Proposed

## 3. Context
As defined in ADR-025, IVORQ's Revenue Engine strictly defers income until the service is delivered. However, modern hospitality pricing is heavily bundled. A "Valentine's Package" might cost $500 and include a Room, a Dinner, a Spa Treatment, and 5,000 Loyalty Points. If the system simply dumps $500 into "Room Revenue," it severely distorts departmental performance (the F&B and Spa teams do the work but show zero revenue). Furthermore, it violates IFRS 15 (Revenue from Contracts with Customers), which mandates that revenue from bundled contracts must be mathematically unbundled into distinct "Performance Obligations" and recognized independently as each service is rendered. 

## 4. Problem Statement
Without a dynamic allocation engine, hotels resort to hardcoded split rules (e.g., "$300 Room, $200 F&B"). These hardcoded splits break instantly when a package is discounted or when tax rates differ across departments. Moreover, loyalty programs create hidden liabilities. If a guest pays $1,000 and earns points worth $50, the hotel has not earned $1,000; it has earned $950 and owes the guest $50 of future service. If loyalty point values and unredeemed gift cards (Breakage) are not treated as Deferred Revenue, the Balance Sheet will hide massive liabilities, leading to severe audit failures and potentially disastrous cash flow deficits when points are redeemed en masse.

## 5. Decision
IVORQ will implement an **IFRS 15 Relative Standalone Selling Price (SSP) Engine**. All bundled packages will be dynamically disassembled into distinct Performance Obligations based on their individual retail value. Discounts will be allocated proportionally across all components. Loyalty points awarded during a stay will automatically carve out a portion of the total transaction value into a `Deferred Loyalty Liability` account. Unredeemed gift cards and expired package components will be governed by a strict "Breakage" recognition schedule.

## 6. Performance Obligation Rules
1. **Identification:** Every component of a package (Room, Breakfast, Spa, Points) is a distinct Performance Obligation (POB).
2. **Timing of Recognition:** Revenue for each POB is recognized only when that specific service is consumed (e.g., Spa revenue hits when the massage occurs; Room revenue hits via the Night Audit).
3. **"Free" is a Fiction:** A package advertising "Free Breakfast" must still allocate revenue to F&B. The discount is spread across the entire package; the breakfast is not financially zero.

## 7. Package Allocation Rules (Relative SSP)
IVORQ will execute the following mathematical steps at the time of booking/posting:
- **Determine Total SSP:** Sum the retail prices of all components. (e.g., Room=$400, Dinner=$100, Spa=$100. Total SSP = $600).
- **Determine Allocation Ratio:** Room = 66.6%, Dinner = 16.6%, Spa = 16.6%.
- **Apply to Package Price:** If the guest pays $450 for the package (a $150 discount):
  - Room Revenue = $450 * 66.6% = $300.00
  - F&B Revenue = $450 * 16.6% = $75.00
  - Spa Revenue = $450 * 16.6% = $75.00
- **Tax Application:** Taxes are calculated *after* the allocation, based on the specific tax rules for that department (e.g., F&B might have 10% VAT, while Spa has 20% VAT).

## 8. Loyalty Rules
- **Accrual:** When a guest earns loyalty points on a $1,000 stay, the engine calculates the fair value of those points (e.g., $50). 
- **Deferral:** The system posts: `Dr Cash $1,000 | Cr Room Revenue $950 | Cr Deferred Loyalty Liability $50`.
- **Redemption:** When the guest redeems the points for a free night, the system posts: `Dr Deferred Loyalty Liability $50 | Cr Room Revenue $50`.

## 9. Gift Card Rules
- Sale of a Gift Card: `Dr Cash | Cr Unearned Revenue (Gift Cards)`.
- **Breakage (Unredeemed Cards):** Based on historical data, if 10% of gift cards are never used, IVORQ will proportionally recognize that 10% as "Breakage Revenue" over the expected redemption period. If escheatment laws apply (where unused funds must be given to the government), the breakage transfers to an `Escheatment Liability` rather than Revenue.

## 10. Deferred Revenue Rules
- If a guest checks out but didn't use their package Dinner ($75 allocated value), does the hotel refund them?
- If the package is "Use it or Lose it", the remaining Performance Obligation is satisfied upon check-out (the guest forfeited the right to the meal).
- The Night Audit triggers a Breakage sweep: `Dr Unearned Revenue (Package Holds) $75 | Cr F&B Breakage Revenue $75`.

## 11. Reporting Requirements
1. **SSP Allocation Audit Trail:** A report proving exactly how a package was unbundled and discounted, required for external auditors.
2. **Loyalty Liability Aging:** Total outstanding point liability across the hotel group.
3. **Departmental Package Contribution:** Real revenue driven by packages vs à la carte sales.

## 12. Audit Requirements
- The Standalone Selling Prices (SSP) for package components must be maintained in an auditable master data table. If the retail price of a Spa treatment changes, the SSP ratio for future package allocations must update automatically, but historical allocations must remain frozen.

## 13. Risks
- **Tax Calculation Nightmares:** If the package price is quoted as "Tax Inclusive," but the underlying components have different tax rates (e.g., Room 10%, Alcohol 20%), the math to back out the taxes while simultaneously allocating the SSP discount requires solving simultaneous equations. Small rounding errors will cause the journal entry to fail the GL balancing rule (ADR-016).
- **POS Integration Disconnect:** When the guest eats the package dinner, the POS will ring up a $100 steak. But IVORQ only allocated $75 to the F&B Revenue bucket. The POS interface (ADR-021) must be smart enough to recognize the guest check is tied to a package and suppress the standard $100 POS revenue posting, instead triggering the release of the $75 deferred package revenue.

## 14. Advantages
- Total compliance with IFRS 15.
- Fair, un-manipulatable revenue distribution across operational departments, preventing GM favoritism in budget allocations.
- Protects the hotel from catastrophic hidden liabilities tied to loyalty points.

## 15. Trade-Offs
- Massive mathematical complexity. Every package reservation becomes a multi-line, deferred-revenue accounting puzzle that must be recalculated if the guest modifies their stay duration or package inclusions mid-trip.

## 16. Consequences
- The PMS/Booking Engine must implement a `PackageAllocationService` capable of parsing multi-component reservations and distributing the financial ledgers.
- The POS integration (ADR-021) must be heavily modified to support "Package Entitlement Redemptions" (zero-revenue POS tickets that trigger deferred revenue release).
