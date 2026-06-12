export interface User {
    id: string;
    property_id: string | null;
    name: string;
    email: string;
    phone: string | null;
    avatar: string | null;
    department_id: string | null;
    position_id: string | null;
    is_active: boolean;
    is_super_admin: boolean;
    roles: string[];
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface Property {
    id: string;
    company_id: string;
    name: string;
    slug: string;
    code: string;
    email: string | null;
    phone: string | null;
    address: string | null;
    city: string | null;
    country: string | null;
    timezone: string;
    currency: string;
    logo: string | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

export interface Department {
    id: string;
    property_id: string;
    name: string;
    code: string;
    description: string | null;
    is_active: boolean;
    positions_count?: number;
    created_at: string;
    updated_at: string;
}

export interface EnumOption {
    value: string | number;
    label: string;
}

export interface Zone {
    id: string;
    property_id: string;
    zone_code: string;
    zone_name: string;
    zone_type: EnumOption;
    description: string | null;
    status: EnumOption;
    priority: EnumOption;
    created_by: string | null;
    updated_by: string | null;
    created_at: string;
    updated_at: string;
    active_assignments_count?: number;
    assignments?: ZoneAssignment[];
    histories?: ZoneHistory[];
}

export interface ZoneAssignment {
    id: string;
    zone_id: string;
    property_id: string;
    user_id: string;
    department_id: string;
    start_date: string;
    end_date: string | null;
    status: EnumOption;
    created_at: string;
    updated_at: string;
    zone?: Zone;
    user?: { id: string; name: string; email: string };
    department?: { id: string; name: string };
}

export interface ZoneHistory {
    id: string;
    property_id: string | null;
    zone_id: string | null;
    action: string;
    remarks: string | null;
    created_at: string;
    performer?: { id: string; name: string } | null;
}

export interface ZoneTemplate {
    id: string;
    property_id: string;
    template_name: string;
    zone_type: EnumOption;
    default_priority: EnumOption;
    description: string | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

// ── Housekeeping ─────────────────────────────────────────────────────────────

export interface Room {
    id: string;
    property_id: string;
    zone_id: string | null;
    room_number: string;
    room_name: string | null;
    room_type: EnumOption;
    floor: string | null;
    building: string | null;
    cleanliness_status: EnumOption;
    occupancy_status: EnumOption | null;
    is_active: boolean;
    notes: string | null;
    created_by: string | null;
    updated_by: string | null;
    created_at: string;
    updated_at: string;
    zone?: { id: string; zone_code: string; zone_name: string };
    status_histories?: RoomStatusHistory[];
    inspections?: RoomInspection[];
}

export interface RoomStatusHistory {
    id: string;
    room_id: string;
    status_field: string;
    from_status: string | null;
    to_status: string;
    action: string;
    remarks: string | null;
    created_at: string;
    performer?: { id: string; name: string } | null;
}

export interface CleaningTask {
    id: string;
    property_id: string;
    room_id: string | null;
    zone_id: string | null;
    task_code: string;
    title: string;
    description: string | null;
    task_type: EnumOption;
    status: EnumOption;
    priority: number;
    estimated_duration_minutes: number | null;
    due_date: string | null;
    started_at: string | null;
    completed_at: string | null;
    completed_by: string | null;
    actual_duration_minutes: number | null;
    created_by: string | null;
    updated_by: string | null;
    created_at: string;
    updated_at: string;
    room?: { id: string; room_number: string; room_name: string | null };
    zone?: { id: string; zone_code: string; zone_name: string };
    assignments?: TaskAssignment[];
}

export interface TaskAssignment {
    id: string;
    property_id: string;
    cleaning_task_id: string;
    user_id: string | null;
    department_id: string | null;
    assigned_by: string | null;
    assigned_at: string;
    completed_at: string | null;
    notes: string | null;
    status: EnumOption;
    created_at: string;
    updated_at: string;
    task?: { id: string; task_code: string; title: string };
    user?: { id: string; name: string } | null;
    department?: { id: string; name: string } | null;
}

export interface RoomInspection {
    id: string;
    property_id: string;
    room_id: string;
    cleaning_task_id: string | null;
    inspector_id: string | null;
    inspection_type: EnumOption;
    status: EnumOption;
    inspection_severity: EnumOption | null;
    remarks: string | null;
    inspected_at: string | null;
    created_by: string | null;
    updated_by: string | null;
    created_at: string;
    updated_at: string;
    room?: { id: string; room_number: string; room_name: string | null };
    task?: { id: string; task_code: string; title: string } | null;
    inspector?: { id: string; name: string } | null;
}

export interface CleaningChecklist {
    id: string;
    property_id: string;
    name: string;
    task_type: EnumOption | null;
    description: string | null;
    is_active: boolean;
    items_count?: number;
    created_by: string | null;
    updated_by: string | null;
    created_at: string;
    updated_at: string;
    items?: ChecklistItem[];
}

export interface ChecklistItem {
    id: string;
    property_id: string;
    checklist_id: string;
    item_text: string;
    sort_order: number;
    is_required: boolean;
    created_at: string;
    updated_at: string;
}

export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

export interface PageProps {
    auth: {
        user: User | null;
        permissions: string[];
    };
    flash: {
        success: string | null;
        error: string | null;
    };
    [key: string]: unknown;
}

// ── Engineering ─────────────────────────────────────────────────────────────

export interface WorkOrder {
    id: string;
    property_id: string;
    work_order_number: string;
    title: string;
    description: string | null;
    work_order_type: EnumOption | string;
    priority: number | EnumOption;
    status: EnumOption | string;
    location_type: string | null;
    room_id: string | null;
    zone_id: string | null;
    location_description: string | null;
    asset_description: string | null;
    due_date: string | null;
    started_at: string | null;
    completed_at: string | null;
    created_at: string;
    updated_at: string;
    room?: { id: string; room_number: string; room_name: string | null };
    zone?: { id: string; zone_code: string; zone_name: string };
    assignments?: any[];
}

export interface PreventiveMaintenance {
    id: string;
    property_id: string;
    title: string;
    description: string | null;
    frequency: string | EnumOption;
    status: string | EnumOption;
    next_due_date: string | null;
    last_completed_date: string | null;
    created_at: string;
    updated_at: string;
}

export interface PreventiveMaintenanceTask {
    id: string;
    preventive_maintenance_id: string;
    title: string;
    status: string | EnumOption;
    due_date: string | null;
    completed_at: string | null;
    created_at: string;
    updated_at: string;
    preventive_maintenance?: PreventiveMaintenance;
}

export interface AssetRequest {
    id: string;
    property_id: string;
    request_number: string;
    title: string;
    description: string | null;
    status: string | EnumOption;
    priority: number | EnumOption;
    requested_by: string | null;
    created_at: string;
    updated_at: string;
}

export interface EngineeringChecklist {
    id: string;
    property_id: string;
    name: string;
    description: string | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
    items?: any[];
}

// ── PMS ─────────────────────────────────────────────────────────────────────

export interface Guest {
    id: string;
    property_id: string;
    guest_code: string;
    full_name: string;
    email: string | null;
    phone: string | null;
    nationality: string | null;
    id_type: string | null;
    id_number: string | null;
    guest_type: EnumOption;
    vip_level: number | null;
    notes: string | null;
    created_at: string;
    updated_at: string;
    reservations?: Reservation[];
    stays?: Stay[];
    folios?: Folio[];
}

export interface RatePlan {
    id: string;
    property_id: string;
    rate_code: string;
    rate_name: string;
    plan_type: EnumOption;
    base_rate: number;
    currency: string;
    is_active: boolean;
    description: string | null;
    created_at: string;
    updated_at: string;
}

export interface Reservation {
    id: string;
    property_id: string;
    reservation_number: string;
    primary_guest_id: string;
    rate_plan_id: string | null;
    adults: number;
    children: number;
    arrival_date: string;
    departure_date: string;
    nights: number;
    reservation_source: EnumOption;
    status: EnumOption;
    reserved_room_type: EnumOption;
    assigned_room_id: string | null;
    remarks: string | null;
    created_at: string;
    updated_at: string;
    primary_guest?: Guest;
    guests?: Guest[];
    rate_plan?: RatePlan;
    assigned_room?: {
        id: string;
        room_number: string;
        room_name: string | null;
        room_type: EnumOption;
        cleanliness_status: EnumOption;
        occupancy_status: EnumOption | null;
    } | null;
    stays?: Stay[];
    stays_count?: number;
    folios?: Folio[];
    folios_count?: number;
}

export interface Stay {
    id: string;
    property_id: string;
    reservation_id: string;
    room_id: string;
    guest_id: string;
    status: EnumOption;
    check_in_at: string;
    expected_departure_at: string | null;
    check_out_at: string | null;
    duration_minutes: number | null;
    created_at: string;
    updated_at: string;
    reservation?: Reservation;
    guest?: Guest;
    room?: {
        id: string;
        room_number: string;
        room_name: string | null;
        room_type: EnumOption;
    };
}

export interface RoomBlock {
    id: string;
    property_id: string;
    room_id: string;
    block_type: EnumOption;
    status: EnumOption;
    reason: EnumOption | null;
    notes: string | null;
    start_at: string;
    end_at: string | null;
    released_at: string | null;
    released_by: string | null;
    created_at: string;
    updated_at: string;
    room?: {
        id: string;
        room_number: string;
        room_name: string | null;
        room_type: EnumOption;
    } | null;
    released_by_user?: { id: string; name: string } | null;
}

export interface FolioItem {
    id: string;
    property_id: string;
    folio_id: string;
    item_type: EnumOption;
    description: string;
    quantity: number;
    amount: number;
    is_void: boolean;
    posted_at: string | null;
    posted_by: string | null;
    created_at: string;
    folio?: { id: string; folio_number: string } | null;
    posted_by_user?: { id: string; name: string } | null;
}

export interface Folio {
    id: string;
    property_id: string;
    folio_number: string;
    reservation_id: string | null;
    guest_id: string | null;
    status: EnumOption;
    currency: string;
    total_charges: number;
    total_payments: number;
    balance: number;
    created_at: string;
    updated_at: string;
    reservation?: { id: string; reservation_number: string } | null;
    guest?: Guest;
    items?: FolioItem[];
    active_items?: FolioItem[];
    items_count?: number;
}

// ── Inventory ────────────────────────────────────────────────────────────────

export interface InventoryCategory {
    id: string;
    property_id: string;
    category_code: string;
    name: string;
    description: string | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

export interface InventoryUnit {
    id: string;
    property_id: string;
    unit_code: string;
    name: string;
    abbreviation: string;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

export interface InventoryLocation {
    id: string;
    property_id: string;
    location_code: string;
    name: string;
    location_type: EnumOption;
    description: string | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

export interface InventoryItem {
    id: string;
    property_id: string;
    item_code: string;
    name: string;
    description: string | null;
    category_id: string;
    unit_id: string;
    average_cost: number;
    reorder_point: number;
    is_active: boolean;
    item_status: EnumOption;
    created_at: string;
    updated_at: string;
    category?: InventoryCategory;
    unit?: InventoryUnit;
    stock_balances?: InventoryStockBalance[];
}

export interface InventoryStockBalance {
    id: string;
    property_id: string;
    item_id: string;
    location_id: string;
    quantity: number;
    item?: InventoryItem;
    location?: InventoryLocation;
}

export interface InventoryStockCard {
    id: string;
    property_id: string;
    item_id: string;
    location_id: string;
    movement_type: EnumOption;
    reference_type: string | null;
    reference_id: string | null;
    quantity_in: number;
    quantity_out: number;
    quantity_balance: number;
    unit_cost: number;
    total_value: number;
    transacted_at: string;
    item?: InventoryItem;
    location?: InventoryLocation;
}

export interface InventoryReceiptLine {
    id: string;
    property_id: string;
    receipt_id: string;
    item_id: string;
    location_id: string;
    quantity: number;
    unit_cost: number;
    total_value: number;
    notes: string | null;
    item?: InventoryItem;
    location?: InventoryLocation;
}

export interface InventoryReceipt {
    id: string;
    property_id: string;
    receipt_number: string;
    supplier_name: string | null;
    external_reference: string | null;
    status: EnumOption;
    received_at: string | null;
    posted_at: string | null;
    posted_by: string | null;
    cancelled_at: string | null;
    cancelled_by: string | null;
    remarks: string | null;
    lines_count?: number;
    lines?: InventoryReceiptLine[];
    created_at: string;
    updated_at: string;
}

export interface InventoryIssueLine {
    id: string;
    property_id: string;
    issue_id: string;
    item_id: string;
    location_id: string;
    quantity: number;
    remarks: string | null;
    item?: InventoryItem;
    location?: InventoryLocation;
}

export interface InventoryIssue {
    id: string;
    property_id: string;
    issue_number: string;
    department_id: string | null;
    issued_to_type: string | null;
    issued_to_id: string | null;
    status: EnumOption;
    issued_at: string | null;
    posted_at: string | null;
    posted_by: string | null;
    cancelled_at: string | null;
    cancelled_by: string | null;
    remarks: string | null;
    lines_count?: number;
    lines?: InventoryIssueLine[];
    created_at: string;
    updated_at: string;
}

export interface InventoryTransferLine {
    id: string;
    property_id: string;
    transfer_id: string;
    item_id: string;
    quantity_requested: number;
    notes: string | null;
    item?: InventoryItem;
}

export interface InventoryTransfer {
    id: string;
    property_id: string;
    transfer_number: string;
    from_location_id: string;
    to_location_id: string;
    status: EnumOption;
    requested_by: string | null;
    approved_by: string | null;
    approved_at: string | null;
    completed_by: string | null;
    completed_at: string | null;
    cancelled_by: string | null;
    cancelled_at: string | null;
    notes: string | null;
    lines_count?: number;
    lines?: InventoryTransferLine[];
    from_location?: InventoryLocation;
    to_location?: InventoryLocation;
    created_at: string;
    updated_at: string;
}

export interface InventoryAdjustmentLine {
    id: string;
    property_id: string;
    adjustment_id: string;
    item_id: string;
    quantity_system: number;
    quantity_actual: number;
    quantity_variance: number;
    unit_cost: number | null;
    notes: string | null;
    item?: InventoryItem;
}

export interface InventoryAdjustment {
    id: string;
    property_id: string;
    adjustment_number: string;
    location_id: string;
    adjustment_type: EnumOption;
    status: EnumOption;
    reason: string;
    submitted_by: string | null;
    submitted_at: string | null;
    approved_by: string | null;
    approved_at: string | null;
    rejected_by: string | null;
    rejected_at: string | null;
    rejection_reason: string | null;
    lines_count?: number;
    lines?: InventoryAdjustmentLine[];
    location?: InventoryLocation;
    created_at: string;
    updated_at: string;
}
