export type { InputHTMLAttributes } from 'react';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}
