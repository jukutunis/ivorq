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
