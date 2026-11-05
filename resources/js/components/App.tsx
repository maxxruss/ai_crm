import { useCallback, useEffect, useState } from 'react';
import { Alert, CircularProgress, Container, Stack } from '@mui/material';
import DocumentManager, { type WorkspaceSession } from './DocumentManager';
import LoginForm from './LoginForm';

export default function App() {
    const [session, setSession] = useState<WorkspaceSession | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const loadSession = useCallback(async () => {
        const response = await fetch('/api/session', { headers: { Accept: 'application/json' } });

        if (response.status === 401) {
            setSession(null);
            return;
        }
        if (!response.ok) {
            throw new Error(`Не удалось проверить сессию (${response.status}).`);
        }

        const payload = (await response.json()) as WorkspaceSession;
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.setAttribute('content', payload.csrf_token);
        setSession(payload);
    }, []);

    useEffect(() => {
        void loadSession()
            .catch((requestError: unknown) => {
                setError(requestError instanceof Error ? requestError.message : 'Не удалось подключиться к серверу.');
            })
            .finally(() => setLoading(false));
    }, [loadSession]);

    async function logout() {
        const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        const response = await fetch('/logout', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
        });
        const payload = await response.json().catch(() => ({}));
        if (typeof payload.csrf_token === 'string') {
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.setAttribute('content', payload.csrf_token);
        }
        setSession(null);
    }

    if (loading) {
        return <Stack alignItems="center" sx={{ py: 12 }}><CircularProgress /></Stack>;
    }

    return (
        <Container maxWidth="lg" sx={{ py: { xs: 3, sm: 6 } }}>
            {error && <Alert severity="error" sx={{ mb: 3 }}>{error}</Alert>}
            {session
                ? <DocumentManager session={session} onLogout={logout} onUnauthorized={() => setSession(null)} />
                : <LoginForm onAuthenticated={loadSession} />}
        </Container>
    );
}
