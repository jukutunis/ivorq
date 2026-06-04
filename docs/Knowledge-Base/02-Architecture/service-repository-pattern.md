# Service-Repository Pattern

Project: IVORQ Hotel Operations Platform
Version: 1.0 — Sprint 03.2 Hardening
Status: Active

---

## Overview

IVORQ enforces a strict four-layer architecture within each module. The layers
have hard responsibility boundaries. No layer may reach into another layer's
concern. Violations degrade testability, create hidden coupling, and make future
modules harder to build consistently.

```
HTTP Request
    │
    ▼
┌─────────────────┐
│   Controller    │  Thin. HTTP only. Delegates immediately.
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│    Service      │  Business logic. Orchestrates. Fires events.
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│   Repository    │  Data access. Queries only. No business logic.
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│     Model       │  Relationships and casts only. No logic.
└─────────────────┘
```

---

## 1. Controllers — Thin HTTP Handlers

Controllers are responsible for:
- Receiving the HTTP request
- Authorizing via `$this->authorize()` or policy calls
- Validating via FormRequest
- Delegating to the service
- Returning the response (Inertia render or JSON)

Controllers must **never**:
- Contain business logic
- Query the database directly
- Call Eloquent outside of delegating to a service
- Make decisions about data structure or workflow

**Correct:**

```php
public function store(StoreRoomRequest $request, RoomService $service): RedirectResponse
{
    $service->create(array_merge($request->validated(), [
        'property_id' => app(CurrentPropertyService::class)->getId(),
    ]));

    return redirect()->route('rooms.index');
}
```

**Wrong:**

```php
public function store(Request $request): RedirectResponse
{
    // Business logic in controller — forbidden
    $room = Room::create($request->all());
    if ($room->zone_id) {
        $zone = Zone::find($room->zone_id);
        $zone->rooms()->attach($room->id);
    }
    event(new RoomCreated($room));
    return redirect()->route('rooms.index');
}
```

---

## 2. Services — Business Logic

Services contain all domain rules, workflows, and orchestration. They are the
only layer that decides *what to do* with data.

Services are responsible for:
- Enforcing domain invariants (e.g., a task cannot be assigned to a deleted zone)
- Coordinating multiple repository calls in the correct order
- Firing events after state changes
- Applying calculated values before persistence (e.g., status transitions)

Services must **never**:
- Build HTTP responses
- Access `$request` directly
- Contain raw SQL or Eloquent query building beyond what repositories offer

**Correct:**

```php
public function create(array $data): CleaningTask
{
    $task = $this->repository->create($data);

    event(new CleaningTaskCreated($task));

    return $task;
}

public function complete(CleaningTask $task, array $data): CleaningTask
{
    // Business rule: only in-progress tasks can be completed
    if (! $task->isInProgress()) {
        throw new InvalidStatusTransitionException($task->status, 'completed');
    }

    $task = $this->repository->update($task, array_merge($data, [
        'status'       => CleaningTaskStatusEnum::Completed,
        'completed_at' => now(),
        'completed_by' => auth()->id(),
    ]));

    event(new CleaningTaskCompleted($task));

    return $task;
}
```

---

## 3. Repositories — Data Access

Repositories are the only layer that writes Eloquent queries. They receive
plain arrays or model instances and return models or collections.

Repositories are responsible for:
- `create()`, `update()`, `delete()` operations
- Scope-aware queries (listing, filtering, searching)
- Pagination
- Eager loading for performance

Repositories must **never**:
- Apply business rules (e.g., "only allow if status is X")
- Fire events
- Call other repositories directly
- Call services

```php
// Correct — repository method only queries
public function findActiveForProperty(string $propertyId): Collection
{
    return CleaningTask::forProperty($propertyId)
        ->where('status', CleaningTaskStatusEnum::Pending)
        ->orderBy('due_date')
        ->get();
}
```

---

## 4. Models — Relationships and Casts Only

Models define the data structure and its relationships. They contain no logic.

Models are responsible for:
- `$fillable` declaration
- `$casts` declaration
- Relationship methods (`hasMany`, `belongsTo`, etc.)
- Trait usage (`BelongsToProperty`, `HasUlid`, `SoftDeletes`, etc.)
- Simple accessor helpers where the value is purely derived from the model's own data

Models must **never**:
- Call services or repositories
- Contain business rules or conditional logic
- Fire events (use observers or services instead)
- Access `auth()` or session state

```php
// Correct — model has only structure and relationships
class CleaningTask extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $fillable = ['property_id', 'task_code', 'title', 'status', ...];

    protected $casts = [
        'task_type' => TaskTypeEnum::class,
        'status'    => CleaningTaskStatusEnum::class,
        'due_date'  => 'date',
    ];

    public function room(): BelongsTo { return $this->belongsTo(Room::class); }
    public function zone(): BelongsTo { return $this->belongsTo(Zone::class); }
}
```

---

## 5. Events, Listeners, and Observers

### Events

Events represent **facts** — things that happened. They are fired by services
after a state change is committed. They carry the affected model as their payload.

```
CleaningTaskCreated
CleaningTaskCompleted
CleaningTaskCancelled
ZoneAssigned
ZoneReassigned
RoomStatusChanged
```

Events must **not** be fired inside controllers or models.

### Listeners

Listeners react to events and perform side effects. Current uses:

- **Audit logging** — `AuditObserver` records model lifecycle changes
- **Activity logging** — listeners record user-facing activity feeds
- **Status history** — `RoomObserver` records `RoomStatusHistory` entries
- **Task history** — `RecordTaskHistory` listener records task state changes

Listeners must not call services that would create further events (avoid
listener loops). They perform one focused side effect per listener.

### Observers

Observers handle Eloquent model lifecycle hooks that apply to all instances of
a model regardless of which service layer created them. Current observers:

| Observer | Model | Responsibility |
|----------|-------|---------------|
| `AuditObserver` | All audited models | Records `created`, `updated`, `deleted` to `audit_logs` |
| `CleaningTaskObserver` | `CleaningTask` | Triggers status history recording |
| `RoomObserver` | `Room` | Records `RoomStatusHistory` on status changes |
| `ZoneObserver` | `Zone` | Records `ZoneHistory` on status transitions |
| `ZoneAssignmentObserver` | `ZoneAssignment` | Records assignment lifecycle events |

Observers must only react to the model's own state. They must not call services.

---

## 6. FormRequests — Validation Layer

FormRequests sit at the HTTP boundary. They are responsible for:
- Authorizing the request (calling the relevant Policy)
- Validating and transforming incoming data
- Enforcing property-scoped existence checks (see validation-hardening-rules.md)

FormRequests must **not**:
- Contain business logic
- Query data beyond what is needed to validate
- Mutate application state

---

## Module Folder Structure

Each module follows this structure:

```
Modules/{Domain}/{Module}/
├── Http/
│   ├── Controllers/
│   ├── Requests/           (FormRequests)
│   └── Resources/          (Inertia page props, if needed)
├── Models/
├── Services/
├── Repositories/
├── Events/
├── Listeners/
├── Observers/
├── Policies/
├── Enums/
├── database/
│   ├── migrations/
│   └── seeders/
└── {Module}ServiceProvider.php
```

All modules register their services, repositories, policies, and observers
in their respective ServiceProvider. No cross-module direct class instantiation
is permitted — use constructor injection and service container binding.
