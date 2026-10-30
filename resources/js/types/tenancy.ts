export type SharedSchool = {
    name: string;
    name_bn: string | null;
    slug: string;
    locale: string;
    timezone: string;
    currency: string;
};

export type PlatformUser = {
    id: number;
    name: string;
    email: string;
    role: string;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
};

export type SessionOption = { id: number; name: string; is_current: boolean };

export type AcademicClass = {
    id: number;
    name: string;
    name_bn: string | null;
    sections: { id: number; name: string }[];
};
