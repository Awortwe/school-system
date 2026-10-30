export interface User {
    id: number;
    name: string;
    email: string;
    employee_no: string | null;
    phone: string | null;
    photo_url?: string | null;
    is_active: boolean;
    must_change_password: boolean;
    roles: string[];
    permissions: string[];
    department?: string | null;
    designation?: string | null;
}

export interface SchoolSettings {
    name: string;
    short_name: string;
    motto: string | null;
    address: string;
    phone: string;
    email: string;
    logo_url: string;
    primary_color: string;
    navy_color: string;
    gold_color: string;
    sky_color: string;
    current_academic_year: string | null;
    current_term: string | null;
}

export interface Flash {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
}

export interface SharedProps {
    auth: { user: User | null };
    flash: Flash;
    school: SchoolSettings;
    currentYear: { id: number; name: string } | null;
    currentTerm: { id: number; name: string; academic_year_id: number } | null;
    [key: string]: unknown;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

export interface Option {
    id: number | string;
    name: string;
}
