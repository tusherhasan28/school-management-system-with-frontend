import type { PlatformUser } from '@/types/tenancy';

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    permissions: string[];
    isStaff: boolean;
    area: 'staff' | 'teacher' | 'parent' | 'student' | null;
    children: { id: number; name: string; name_bn: string | null }[];
    platformUser: PlatformUser | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
