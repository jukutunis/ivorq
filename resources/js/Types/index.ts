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
    };
    flash: {
        success: string | null;
        error: string | null;
    };
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
    pm_code: string;
    title: string;
    description: string | null;
    frequency: EnumOption;
    status: EnumOption;
    next_due_at: string | null;
    last_run_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface PreventiveMaintenanceTask {
    id: string;
    property_id: string;
    preventive_maintenance_id: string;
    status: EnumOption;
    scheduled_date: string | null;
    completed_at: string | null;
    created_at: string;
    updated_at: string;
    preventive_maintenance?: {
        id: string;
        pm_code: string;
        title: string;
    };
}

export interface AssetRequest {
    id: string;
    property_id: string;
    request_number: string;
    title: string;
    description: string | null;
    status: EnumOption;
    priority: EnumOption;
    requester_id: string | null;
    created_at: string;
    updated_at: string;
}

export interface EngineeringChecklist {
    id: string;
    property_id: string;
    title: string;
    description: string | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
    items?: any[];
}
