import { useState, type FormEvent } from 'react';
import { Alert, Box, Button, Paper, Stack, TextField, Typography } from '@mui/material';

interface LoginFormProps {
    onAuthenticated: () => Promise<void>;
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

export default function LoginForm({ onAuthenticated }: LoginFormProps) {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [submitting, setSubmitting] = useState(false);

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setSubmitting(true);
        setError('');

        try {
            const response = await fetch('/login', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ email, password }),
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message ?? 'Не удалось войти. Проверьте данные.');
            }

            setPassword('');
            await onAuthenticated();
        } catch (requestError) {
            setError(requestError instanceof Error ? requestError.message : 'Ошибка входа.');
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <Paper component="form" onSubmit={submit} variant="outlined" sx={{ p: { xs: 3, sm: 4 }, maxWidth: 460, mx: 'auto', mt: 10 }}>
            <Stack spacing={2.5}>
                <Box>
                    <Typography variant="overline" color="primary">Smart RAG Knowledge Base</Typography>
                    <Typography variant="h4" component="h1" fontWeight={700}>Вход</Typography>
                    <Typography color="text.secondary" sx={{ mt: 1 }}>
                        Войдите, чтобы управлять документами рабочего пространства.
                    </Typography>
                </Box>
                {error && <Alert severity="error">{error}</Alert>}
                <TextField
                    label="Электронная почта"
                    type="email"
                    autoComplete="username"
                    value={email}
                    onChange={(event) => setEmail(event.target.value)}
                    required
                    fullWidth
                />
                <TextField
                    label="Пароль"
                    type="password"
                    autoComplete="current-password"
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                    required
                    fullWidth
                />
                <Button type="submit" variant="contained" size="large" disabled={submitting}>
                    {submitting ? 'Входим…' : 'Войти'}
                </Button>
            </Stack>
        </Paper>
    );
}
