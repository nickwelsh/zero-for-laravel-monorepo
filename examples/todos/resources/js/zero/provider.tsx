import type { ReactNode } from 'react';
import { ZeroProvider } from './generated/react';

export function TodosZeroProvider({ userId, children }: { userId: number; children: ReactNode }) {
    return (
        <ZeroProvider userID={String(userId)} context={{ user_id: userId }}>
            {children}
        </ZeroProvider>
    );
}
