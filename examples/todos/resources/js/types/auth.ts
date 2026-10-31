import type { App, Inertia } from '@/wayfinder/types';

export type User = App.Models.User & {
    avatar?: string;
};

export type Auth = Inertia.SharedData['auth'];

export type Passkey = Inertia.Pages.Settings.Security['passkeys'][number];

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
