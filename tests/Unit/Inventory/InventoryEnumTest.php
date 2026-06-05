<?php

namespace Tests\Unit\Inventory;

use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Enums\AdjustmentTypeEnum;
use Modules\Operations\Inventory\Enums\IssueStatusEnum;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Enums\LocationTypeEnum;
use Modules\Operations\Inventory\Enums\ReceiptStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Enums\TransferStatusEnum;
use PHPUnit\Framework\TestCase;

class InventoryEnumTest extends TestCase
{
    // ══════════════════════════════════════════════════════════════════════
    // TransactionTypeEnum
    // ══════════════════════════════════════════════════════════════════════

    public function test_transaction_type_cases_load_from_value(): void
    {
        $this->assertSame(TransactionTypeEnum::OpeningBalance,  TransactionTypeEnum::from('opening_balance'));
        $this->assertSame(TransactionTypeEnum::PurchaseReceipt, TransactionTypeEnum::from('purchase_receipt'));
        $this->assertSame(TransactionTypeEnum::Issue,           TransactionTypeEnum::from('issue'));
        $this->assertSame(TransactionTypeEnum::TransferOut,     TransactionTypeEnum::from('transfer_out'));
        $this->assertSame(TransactionTypeEnum::TransferIn,      TransactionTypeEnum::from('transfer_in'));
        $this->assertSame(TransactionTypeEnum::AdjustmentIn,    TransactionTypeEnum::from('adjustment_in'));
        $this->assertSame(TransactionTypeEnum::AdjustmentOut,   TransactionTypeEnum::from('adjustment_out'));
        $this->assertSame(TransactionTypeEnum::Return,          TransactionTypeEnum::from('return'));
    }

    public function test_transaction_type_labels(): void
    {
        $this->assertSame('Opening Balance',  TransactionTypeEnum::OpeningBalance->label());
        $this->assertSame('Purchase Receipt', TransactionTypeEnum::PurchaseReceipt->label());
        $this->assertSame('Issue',            TransactionTypeEnum::Issue->label());
        $this->assertSame('Transfer Out',     TransactionTypeEnum::TransferOut->label());
        $this->assertSame('Transfer In',      TransactionTypeEnum::TransferIn->label());
        $this->assertSame('Adjustment In',    TransactionTypeEnum::AdjustmentIn->label());
        $this->assertSame('Adjustment Out',   TransactionTypeEnum::AdjustmentOut->label());
        $this->assertSame('Return',           TransactionTypeEnum::Return->label());
    }

    public function test_transaction_type_has_eight_cases(): void
    {
        $this->assertCount(8, TransactionTypeEnum::cases());
    }

    // ══════════════════════════════════════════════════════════════════════
    // ReceiptStatusEnum
    // ══════════════════════════════════════════════════════════════════════

    public function test_receipt_status_cases_load_from_value(): void
    {
        $this->assertSame(ReceiptStatusEnum::Draft,     ReceiptStatusEnum::from('draft'));
        $this->assertSame(ReceiptStatusEnum::Posted,    ReceiptStatusEnum::from('posted'));
        $this->assertSame(ReceiptStatusEnum::Cancelled, ReceiptStatusEnum::from('cancelled'));
    }

    public function test_receipt_status_labels(): void
    {
        $this->assertSame('Draft',     ReceiptStatusEnum::Draft->label());
        $this->assertSame('Posted',    ReceiptStatusEnum::Posted->label());
        $this->assertSame('Cancelled', ReceiptStatusEnum::Cancelled->label());
    }

    public function test_receipt_status_has_three_cases(): void
    {
        $this->assertCount(3, ReceiptStatusEnum::cases());
    }

    // ── Valid transitions ──────────────────────────────────────────────────

    public function test_receipt_status_draft_to_posted_is_valid(): void
    {
        $this->assertTrue(ReceiptStatusEnum::Draft->canTransitionTo(ReceiptStatusEnum::Posted));
    }

    public function test_receipt_status_draft_to_cancelled_is_valid(): void
    {
        $this->assertTrue(ReceiptStatusEnum::Draft->canTransitionTo(ReceiptStatusEnum::Cancelled));
    }

    // ── Prohibited transitions ─────────────────────────────────────────────

    public function test_receipt_status_posted_to_cancelled_is_prohibited(): void
    {
        $this->assertFalse(ReceiptStatusEnum::Posted->canTransitionTo(ReceiptStatusEnum::Cancelled));
    }

    public function test_receipt_status_posted_to_draft_is_prohibited(): void
    {
        $this->assertFalse(ReceiptStatusEnum::Posted->canTransitionTo(ReceiptStatusEnum::Draft));
    }

    public function test_receipt_status_cancelled_to_draft_is_prohibited(): void
    {
        $this->assertFalse(ReceiptStatusEnum::Cancelled->canTransitionTo(ReceiptStatusEnum::Draft));
    }

    public function test_receipt_status_cancelled_to_posted_is_prohibited(): void
    {
        $this->assertFalse(ReceiptStatusEnum::Cancelled->canTransitionTo(ReceiptStatusEnum::Posted));
    }

    public function test_receipt_status_draft_to_draft_is_prohibited(): void
    {
        $this->assertFalse(ReceiptStatusEnum::Draft->canTransitionTo(ReceiptStatusEnum::Draft));
    }

    // ── Terminal states ────────────────────────────────────────────────────

    public function test_receipt_status_posted_is_terminal(): void
    {
        $this->assertTrue(ReceiptStatusEnum::Posted->isTerminal());
        $this->assertEmpty(ReceiptStatusEnum::validTransitionsFrom(ReceiptStatusEnum::Posted));
    }

    public function test_receipt_status_cancelled_is_terminal(): void
    {
        $this->assertTrue(ReceiptStatusEnum::Cancelled->isTerminal());
        $this->assertEmpty(ReceiptStatusEnum::validTransitionsFrom(ReceiptStatusEnum::Cancelled));
    }

    public function test_receipt_status_draft_is_not_terminal(): void
    {
        $this->assertFalse(ReceiptStatusEnum::Draft->isTerminal());
    }

    // ── validTransitionsFrom counts ────────────────────────────────────────

    public function test_receipt_status_valid_transitions_from_draft(): void
    {
        $transitions = ReceiptStatusEnum::validTransitionsFrom(ReceiptStatusEnum::Draft);
        $this->assertCount(2, $transitions);
        $this->assertContains(ReceiptStatusEnum::Posted,    $transitions);
        $this->assertContains(ReceiptStatusEnum::Cancelled, $transitions);
    }

    // ══════════════════════════════════════════════════════════════════════
    // IssueStatusEnum
    // ══════════════════════════════════════════════════════════════════════

    public function test_issue_status_cases_load_from_value(): void
    {
        $this->assertSame(IssueStatusEnum::Draft,     IssueStatusEnum::from('draft'));
        $this->assertSame(IssueStatusEnum::Posted,    IssueStatusEnum::from('posted'));
        $this->assertSame(IssueStatusEnum::Cancelled, IssueStatusEnum::from('cancelled'));
    }

    public function test_issue_status_labels(): void
    {
        $this->assertSame('Draft',     IssueStatusEnum::Draft->label());
        $this->assertSame('Posted',    IssueStatusEnum::Posted->label());
        $this->assertSame('Cancelled', IssueStatusEnum::Cancelled->label());
    }

    public function test_issue_status_has_three_cases(): void
    {
        $this->assertCount(3, IssueStatusEnum::cases());
    }

    // ── Valid transitions ──────────────────────────────────────────────────

    public function test_issue_status_draft_to_posted_is_valid(): void
    {
        $this->assertTrue(IssueStatusEnum::Draft->canTransitionTo(IssueStatusEnum::Posted));
    }

    public function test_issue_status_draft_to_cancelled_is_valid(): void
    {
        $this->assertTrue(IssueStatusEnum::Draft->canTransitionTo(IssueStatusEnum::Cancelled));
    }

    // ── Prohibited transitions ─────────────────────────────────────────────

    public function test_issue_status_posted_to_cancelled_is_prohibited(): void
    {
        $this->assertFalse(IssueStatusEnum::Posted->canTransitionTo(IssueStatusEnum::Cancelled));
    }

    public function test_issue_status_posted_to_draft_is_prohibited(): void
    {
        $this->assertFalse(IssueStatusEnum::Posted->canTransitionTo(IssueStatusEnum::Draft));
    }

    public function test_issue_status_cancelled_to_draft_is_prohibited(): void
    {
        $this->assertFalse(IssueStatusEnum::Cancelled->canTransitionTo(IssueStatusEnum::Draft));
    }

    public function test_issue_status_cancelled_to_posted_is_prohibited(): void
    {
        $this->assertFalse(IssueStatusEnum::Cancelled->canTransitionTo(IssueStatusEnum::Posted));
    }

    // ── Terminal states ────────────────────────────────────────────────────

    public function test_issue_status_posted_is_terminal(): void
    {
        $this->assertTrue(IssueStatusEnum::Posted->isTerminal());
        $this->assertEmpty(IssueStatusEnum::validTransitionsFrom(IssueStatusEnum::Posted));
    }

    public function test_issue_status_cancelled_is_terminal(): void
    {
        $this->assertTrue(IssueStatusEnum::Cancelled->isTerminal());
        $this->assertEmpty(IssueStatusEnum::validTransitionsFrom(IssueStatusEnum::Cancelled));
    }

    public function test_issue_status_draft_is_not_terminal(): void
    {
        $this->assertFalse(IssueStatusEnum::Draft->isTerminal());
    }

    // ── validTransitionsFrom counts ────────────────────────────────────────

    public function test_issue_status_valid_transitions_from_draft(): void
    {
        $transitions = IssueStatusEnum::validTransitionsFrom(IssueStatusEnum::Draft);
        $this->assertCount(2, $transitions);
        $this->assertContains(IssueStatusEnum::Posted,    $transitions);
        $this->assertContains(IssueStatusEnum::Cancelled, $transitions);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TransferStatusEnum
    // ══════════════════════════════════════════════════════════════════════

    public function test_transfer_status_cases_load_from_value(): void
    {
        $this->assertSame(TransferStatusEnum::Draft,     TransferStatusEnum::from('draft'));
        $this->assertSame(TransferStatusEnum::Submitted, TransferStatusEnum::from('submitted'));
        $this->assertSame(TransferStatusEnum::Completed, TransferStatusEnum::from('completed'));
        $this->assertSame(TransferStatusEnum::Cancelled, TransferStatusEnum::from('cancelled'));
    }

    public function test_transfer_status_labels(): void
    {
        $this->assertSame('Draft',     TransferStatusEnum::Draft->label());
        $this->assertSame('Submitted', TransferStatusEnum::Submitted->label());
        $this->assertSame('Completed', TransferStatusEnum::Completed->label());
        $this->assertSame('Cancelled', TransferStatusEnum::Cancelled->label());
    }

    public function test_transfer_status_has_four_cases(): void
    {
        $this->assertCount(4, TransferStatusEnum::cases());
    }

    // ── Valid transitions ──────────────────────────────────────────────────

    public function test_transfer_status_draft_to_submitted_is_valid(): void
    {
        $this->assertTrue(TransferStatusEnum::Draft->canTransitionTo(TransferStatusEnum::Submitted));
    }

    public function test_transfer_status_draft_to_cancelled_is_valid(): void
    {
        $this->assertTrue(TransferStatusEnum::Draft->canTransitionTo(TransferStatusEnum::Cancelled));
    }

    public function test_transfer_status_submitted_to_completed_is_valid(): void
    {
        $this->assertTrue(TransferStatusEnum::Submitted->canTransitionTo(TransferStatusEnum::Completed));
    }

    public function test_transfer_status_submitted_to_cancelled_is_valid(): void
    {
        $this->assertTrue(TransferStatusEnum::Submitted->canTransitionTo(TransferStatusEnum::Cancelled));
    }

    // ── Prohibited transitions ─────────────────────────────────────────────

    public function test_transfer_status_draft_to_completed_is_prohibited(): void
    {
        $this->assertFalse(TransferStatusEnum::Draft->canTransitionTo(TransferStatusEnum::Completed));
    }

    public function test_transfer_status_submitted_to_draft_is_prohibited(): void
    {
        $this->assertFalse(TransferStatusEnum::Submitted->canTransitionTo(TransferStatusEnum::Draft));
    }

    public function test_transfer_status_completed_to_cancelled_is_prohibited(): void
    {
        $this->assertFalse(TransferStatusEnum::Completed->canTransitionTo(TransferStatusEnum::Cancelled));
    }

    public function test_transfer_status_completed_to_draft_is_prohibited(): void
    {
        $this->assertFalse(TransferStatusEnum::Completed->canTransitionTo(TransferStatusEnum::Draft));
    }

    public function test_transfer_status_cancelled_to_submitted_is_prohibited(): void
    {
        $this->assertFalse(TransferStatusEnum::Cancelled->canTransitionTo(TransferStatusEnum::Submitted));
    }

    // ── Terminal states ────────────────────────────────────────────────────

    public function test_transfer_status_completed_is_terminal(): void
    {
        $this->assertTrue(TransferStatusEnum::Completed->isTerminal());
        $this->assertEmpty(TransferStatusEnum::validTransitionsFrom(TransferStatusEnum::Completed));
    }

    public function test_transfer_status_cancelled_is_terminal(): void
    {
        $this->assertTrue(TransferStatusEnum::Cancelled->isTerminal());
        $this->assertEmpty(TransferStatusEnum::validTransitionsFrom(TransferStatusEnum::Cancelled));
    }

    public function test_transfer_status_non_terminal_states_are_not_terminal(): void
    {
        $this->assertFalse(TransferStatusEnum::Draft->isTerminal());
        $this->assertFalse(TransferStatusEnum::Submitted->isTerminal());
    }

    // ── validTransitionsFrom counts ────────────────────────────────────────

    public function test_transfer_status_valid_transitions_from_draft(): void
    {
        $transitions = TransferStatusEnum::validTransitionsFrom(TransferStatusEnum::Draft);
        $this->assertCount(2, $transitions);
        $this->assertContains(TransferStatusEnum::Submitted, $transitions);
        $this->assertContains(TransferStatusEnum::Cancelled, $transitions);
    }

    public function test_transfer_status_valid_transitions_from_submitted(): void
    {
        $transitions = TransferStatusEnum::validTransitionsFrom(TransferStatusEnum::Submitted);
        $this->assertCount(2, $transitions);
        $this->assertContains(TransferStatusEnum::Completed, $transitions);
        $this->assertContains(TransferStatusEnum::Cancelled, $transitions);
    }

    // ══════════════════════════════════════════════════════════════════════
    // AdjustmentStatusEnum
    // ══════════════════════════════════════════════════════════════════════

    public function test_adjustment_status_cases_load_from_value(): void
    {
        $this->assertSame(AdjustmentStatusEnum::Draft,     AdjustmentStatusEnum::from('draft'));
        $this->assertSame(AdjustmentStatusEnum::Submitted, AdjustmentStatusEnum::from('submitted'));
        $this->assertSame(AdjustmentStatusEnum::Approved,  AdjustmentStatusEnum::from('approved'));
        $this->assertSame(AdjustmentStatusEnum::Rejected,  AdjustmentStatusEnum::from('rejected'));
        $this->assertSame(AdjustmentStatusEnum::Cancelled, AdjustmentStatusEnum::from('cancelled'));
    }

    public function test_adjustment_status_labels(): void
    {
        $this->assertSame('Draft',     AdjustmentStatusEnum::Draft->label());
        $this->assertSame('Submitted', AdjustmentStatusEnum::Submitted->label());
        $this->assertSame('Approved',  AdjustmentStatusEnum::Approved->label());
        $this->assertSame('Rejected',  AdjustmentStatusEnum::Rejected->label());
        $this->assertSame('Cancelled', AdjustmentStatusEnum::Cancelled->label());
    }

    public function test_adjustment_status_has_five_cases(): void
    {
        $this->assertCount(5, AdjustmentStatusEnum::cases());
    }

    // ── Valid transitions ──────────────────────────────────────────────────

    public function test_adjustment_status_draft_to_submitted_is_valid(): void
    {
        $this->assertTrue(AdjustmentStatusEnum::Draft->canTransitionTo(AdjustmentStatusEnum::Submitted));
    }

    public function test_adjustment_status_draft_to_cancelled_is_valid(): void
    {
        $this->assertTrue(AdjustmentStatusEnum::Draft->canTransitionTo(AdjustmentStatusEnum::Cancelled));
    }

    public function test_adjustment_status_submitted_to_approved_is_valid(): void
    {
        $this->assertTrue(AdjustmentStatusEnum::Submitted->canTransitionTo(AdjustmentStatusEnum::Approved));
    }

    public function test_adjustment_status_submitted_to_rejected_is_valid(): void
    {
        $this->assertTrue(AdjustmentStatusEnum::Submitted->canTransitionTo(AdjustmentStatusEnum::Rejected));
    }

    // ── Prohibited transitions ─────────────────────────────────────────────

    public function test_adjustment_status_draft_to_approved_is_prohibited(): void
    {
        $this->assertFalse(AdjustmentStatusEnum::Draft->canTransitionTo(AdjustmentStatusEnum::Approved));
    }

    public function test_adjustment_status_draft_to_rejected_is_prohibited(): void
    {
        $this->assertFalse(AdjustmentStatusEnum::Draft->canTransitionTo(AdjustmentStatusEnum::Rejected));
    }

    public function test_adjustment_status_submitted_to_draft_is_prohibited(): void
    {
        $this->assertFalse(AdjustmentStatusEnum::Submitted->canTransitionTo(AdjustmentStatusEnum::Draft));
    }

    public function test_adjustment_status_submitted_to_cancelled_is_prohibited(): void
    {
        $this->assertFalse(AdjustmentStatusEnum::Submitted->canTransitionTo(AdjustmentStatusEnum::Cancelled));
    }

    public function test_adjustment_status_approved_to_rejected_is_prohibited(): void
    {
        $this->assertFalse(AdjustmentStatusEnum::Approved->canTransitionTo(AdjustmentStatusEnum::Rejected));
    }

    public function test_adjustment_status_rejected_to_approved_is_prohibited(): void
    {
        $this->assertFalse(AdjustmentStatusEnum::Rejected->canTransitionTo(AdjustmentStatusEnum::Approved));
    }

    public function test_adjustment_status_cancelled_to_submitted_is_prohibited(): void
    {
        $this->assertFalse(AdjustmentStatusEnum::Cancelled->canTransitionTo(AdjustmentStatusEnum::Submitted));
    }

    // ── Terminal states ────────────────────────────────────────────────────

    public function test_adjustment_status_approved_is_terminal(): void
    {
        $this->assertTrue(AdjustmentStatusEnum::Approved->isTerminal());
        $this->assertEmpty(AdjustmentStatusEnum::validTransitionsFrom(AdjustmentStatusEnum::Approved));
    }

    public function test_adjustment_status_rejected_is_terminal(): void
    {
        $this->assertTrue(AdjustmentStatusEnum::Rejected->isTerminal());
        $this->assertEmpty(AdjustmentStatusEnum::validTransitionsFrom(AdjustmentStatusEnum::Rejected));
    }

    public function test_adjustment_status_cancelled_is_terminal(): void
    {
        $this->assertTrue(AdjustmentStatusEnum::Cancelled->isTerminal());
        $this->assertEmpty(AdjustmentStatusEnum::validTransitionsFrom(AdjustmentStatusEnum::Cancelled));
    }

    public function test_adjustment_status_non_terminal_states_are_not_terminal(): void
    {
        $this->assertFalse(AdjustmentStatusEnum::Draft->isTerminal());
        $this->assertFalse(AdjustmentStatusEnum::Submitted->isTerminal());
    }

    // ── validTransitionsFrom counts ────────────────────────────────────────

    public function test_adjustment_status_valid_transitions_from_draft(): void
    {
        $transitions = AdjustmentStatusEnum::validTransitionsFrom(AdjustmentStatusEnum::Draft);
        $this->assertCount(2, $transitions);
        $this->assertContains(AdjustmentStatusEnum::Submitted, $transitions);
        $this->assertContains(AdjustmentStatusEnum::Cancelled, $transitions);
    }

    public function test_adjustment_status_valid_transitions_from_submitted(): void
    {
        $transitions = AdjustmentStatusEnum::validTransitionsFrom(AdjustmentStatusEnum::Submitted);
        $this->assertCount(2, $transitions);
        $this->assertContains(AdjustmentStatusEnum::Approved, $transitions);
        $this->assertContains(AdjustmentStatusEnum::Rejected, $transitions);
    }

    // ══════════════════════════════════════════════════════════════════════
    // AdjustmentTypeEnum
    // ══════════════════════════════════════════════════════════════════════

    public function test_adjustment_type_cases_load_from_value(): void
    {
        $this->assertSame(AdjustmentTypeEnum::StockTake,  AdjustmentTypeEnum::from('stock_take'));
        $this->assertSame(AdjustmentTypeEnum::Damaged,    AdjustmentTypeEnum::from('damaged'));
        $this->assertSame(AdjustmentTypeEnum::Lost,       AdjustmentTypeEnum::from('lost'));
        $this->assertSame(AdjustmentTypeEnum::Found,      AdjustmentTypeEnum::from('found'));
        $this->assertSame(AdjustmentTypeEnum::Correction, AdjustmentTypeEnum::from('correction'));
    }

    public function test_adjustment_type_labels(): void
    {
        $this->assertSame('Stock Take',  AdjustmentTypeEnum::StockTake->label());
        $this->assertSame('Damaged',     AdjustmentTypeEnum::Damaged->label());
        $this->assertSame('Lost',        AdjustmentTypeEnum::Lost->label());
        $this->assertSame('Found',       AdjustmentTypeEnum::Found->label());
        $this->assertSame('Correction',  AdjustmentTypeEnum::Correction->label());
    }

    public function test_adjustment_type_has_five_cases(): void
    {
        $this->assertCount(5, AdjustmentTypeEnum::cases());
    }

    // ══════════════════════════════════════════════════════════════════════
    // LocationTypeEnum
    // ══════════════════════════════════════════════════════════════════════

    public function test_location_type_cases_load_from_value(): void
    {
        $this->assertSame(LocationTypeEnum::MainStore,       LocationTypeEnum::from('main_store'));
        $this->assertSame(LocationTypeEnum::DepartmentStore, LocationTypeEnum::from('department_store'));
        $this->assertSame(LocationTypeEnum::MinibarStore,    LocationTypeEnum::from('minibar_store'));
        $this->assertSame(LocationTypeEnum::LaundryStore,    LocationTypeEnum::from('laundry_store'));
        $this->assertSame(LocationTypeEnum::Other,           LocationTypeEnum::from('other'));
    }

    public function test_location_type_labels(): void
    {
        $this->assertSame('Main Store',       LocationTypeEnum::MainStore->label());
        $this->assertSame('Department Store', LocationTypeEnum::DepartmentStore->label());
        $this->assertSame('Minibar Store',    LocationTypeEnum::MinibarStore->label());
        $this->assertSame('Laundry Store',    LocationTypeEnum::LaundryStore->label());
        $this->assertSame('Other',            LocationTypeEnum::Other->label());
    }

    public function test_location_type_has_five_cases(): void
    {
        $this->assertCount(5, LocationTypeEnum::cases());
    }

    // ══════════════════════════════════════════════════════════════════════
    // ItemStatusEnum
    // ══════════════════════════════════════════════════════════════════════

    public function test_item_status_cases_load_from_value(): void
    {
        $this->assertSame(ItemStatusEnum::InStock,    ItemStatusEnum::from('in_stock'));
        $this->assertSame(ItemStatusEnum::LowStock,   ItemStatusEnum::from('low_stock'));
        $this->assertSame(ItemStatusEnum::OutOfStock, ItemStatusEnum::from('out_of_stock'));
    }

    public function test_item_status_labels(): void
    {
        $this->assertSame('In Stock',     ItemStatusEnum::InStock->label());
        $this->assertSame('Low Stock',    ItemStatusEnum::LowStock->label());
        $this->assertSame('Out of Stock', ItemStatusEnum::OutOfStock->label());
    }

    public function test_item_status_has_three_cases(): void
    {
        $this->assertCount(3, ItemStatusEnum::cases());
    }
}
