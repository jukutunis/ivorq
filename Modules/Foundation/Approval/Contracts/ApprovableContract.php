<?php

namespace Modules\Foundation\Approval\Contracts;

interface ApprovableContract
{
    /**
     * Get the exact class string for polymorphic relationships.
     */
    public function getApprovableType(): string;

    /**
     * Get the unique ID for this approvable model.
     */
    public function getApprovableId(): string;

    /**
     * Get the property ID this record belongs to.
     */
    public function getPropertyId(): string;

    /**
     * Get the department ID related to this record, if any.
     */
    public function getDepartmentId(): ?string;

    /**
     * Get the financial amount to be approved.
     * Return 0.0 if not applicable.
     */
    public function getApprovalAmount(): float;

    /**
     * Handle state update when approval is completed.
     */
    public function markAsApproved(): void;

    /**
     * Handle state update when approval is rejected.
     */
    public function markAsRejected(?string $reason = null): void;
}
