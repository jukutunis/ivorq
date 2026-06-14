<?php

namespace Shared\Services;

use Shared\Exceptions\PropertyNotResolvedException;

class CurrentPropertyService
{
    private ?string $propertyId = null;

    // ── Primary API ──────────────────────────────────────────────────────────

    public function setPropertyId(?string $propertyId): void
    {
        $this->propertyId = $propertyId;
    }

    public function getPropertyId(): ?string
    {
        // Tier 1: explicit override
        if ($this->propertyId !== null) {
            return $this->propertyId;
        }

        // Tier 2: session-based current_property_id
        try {
            $sessionId = session('current_property_id');
            if ($sessionId) {
                return $sessionId;
            }
        } catch (\Throwable) {
            // Session not available in this context (e.g., console/queue)
        }

        // Tier 3: authenticated user default property
        if (auth()->check() && auth()->user()->defaultProperty()?->id) {
            return auth()->user()->defaultProperty()->id;
        }

        return null;
    }

    public function resolveOrFail(): string
    {
        $propertyId = $this->getPropertyId();

        if ($propertyId === null) {
            throw new PropertyNotResolvedException();
        }

        return $propertyId;
    }

    public function clear(): void
    {
        $this->propertyId = null;
    }

    // ── Backward-compatible aliases ──────────────────────────────────────────

    public function setId(string $propertyId): void
    {
        $this->setPropertyId($propertyId);
    }

    public function getId(): ?string
    {
        return $this->getPropertyId();
    }

    public function resolve(): ?string
    {
        return $this->getPropertyId();
    }

    public function isResolved(): bool
    {
        return $this->getPropertyId() !== null;
    }
}
