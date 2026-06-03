<?php

namespace Shared\Services;

class CurrentPropertyService
{
    private ?string $propertyId = null;

    public function setId(string $propertyId): void
    {
        $this->propertyId = $propertyId;
    }

    public function getId(): ?string
    {
        if ($this->propertyId) {
            return $this->propertyId;
        }

        if (auth()->check() && auth()->user()->property_id) {
            return auth()->user()->property_id;
        }

        return null;
    }

    public function resolve(): ?string
    {
        return $this->getId();
    }

    public function isResolved(): bool
    {
        return $this->getId() !== null;
    }
}
