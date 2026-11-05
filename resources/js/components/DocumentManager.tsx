import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import {
    Alert,
    Avatar,
    Box,
    Button,
    Chip,
    CircularProgress,
    Divider,
    List,
    ListItem,
    ListItemAvatar,
    ListItemText,
    MenuItem,
    Paper,
    Stack,
    TextField,
    Typography,
} from '@mui/material';
import ChatPanel from './ChatPanel';

export interface WorkspaceTenant {
    id: number;
    name: string;
    role: string;
}

export interface WorkspaceSession {
    user: { id: number; name: string; email: string };
    tenants: WorkspaceTenant[];
    active_tenant_id: number | null;
    csrf_token: string;
}

interface DocumentItem {
    id: number;
    title: string;
    source_type: string;
    index_status: 'queued' | 'processing' | 'ready' | 'failed';
    chunk_count: number;
    created_at: string;
    updated_at: string;
}

interface DocumentManagerProps {
    session: WorkspaceSession;
    onLogout: () => Promise<void>;
    onUnauthorized: () => void;
}

const statusLabels: Record<DocumentItem['index_status'], string> = {
    queued: 'В очереди',
    processing: 'Индексируется',
    ready: 'Готов',
    failed: 'Ошибка',
};

const statusColors: Record<DocumentItem['index_status'], 'default' | 'info' | 'success' | 'error'> = {
    queued: 'default',
    processing: 'info',
    ready: 'success',
    failed: 'error',
};

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function errorMessage(response: Response): Promise<string> {
    const payload = await response.json().catch(() => ({}));
    if (payload.errors) {
        const firstError = Object.values(payload.errors).flat()[0];
        if (typeof firstError === 'string') return firstError;
    }

    return payload.message ?? `Ошибка запроса (${response.status}).`;
}

export default function DocumentManager({ session, onLogout, onUnauthorized }: DocumentManagerProps) {
    const [tenantId, setTenantId] = useState(String(session.active_tenant_id ?? ''));
    const [documents, setDocuments] = useState<DocumentItem[]>([]);
    const [file, setFile] = useState<File | null>(null);
    const [title, setTitle] = useState('');
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [loading, setLoading] = useState(false);
    const [uploading, setUploading] = useState(false);

    const selectedTenant = useMemo(
        () => session.tenants.find((tenant) => String(tenant.id) === tenantId),
        [session.tenants, tenantId],
    );
    const canManage = selectedTenant ? ['owner', 'admin'].includes(selectedTenant.role) : false;

    useEffect(() => {
        if (!tenantId && session.active_tenant_id) {
            setTenantId(String(session.active_tenant_id));
        }
    }, [tenantId, session.active_tenant_id]);

    const loadDocuments = useCallback(async () => {
        if (!tenantId) return;

        setLoading(true);
        try {
            const response = await fetch(`/api/documents?tenant_id=${encodeURIComponent(tenantId)}`, {
                headers: { Accept: 'application/json' },
            });

            if (response.status === 401) {
                onUnauthorized();
                return;
            }
            if (!response.ok) throw new Error(await errorMessage(response));

            const payload = (await response.json()) as { documents: DocumentItem[] };
            setDocuments(payload.documents);
            setError('');
        } catch (requestError) {
            setError(requestError instanceof Error ? requestError.message : 'Не удалось загрузить документы.');
        } finally {
            setLoading(false);
        }
    }, [tenantId, onUnauthorized]);

    useEffect(() => {
        void loadDocuments();
    }, [loadDocuments]);

    useEffect(() => {
        if (!documents.some((document) => document.index_status === 'queued' || document.index_status === 'processing')) {
            return undefined;
        }

        const timer = window.setTimeout(() => void loadDocuments(), 2000);
        return () => window.clearTimeout(timer);
    }, [documents, loadDocuments]);

    async function uploadDocument(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!file || !tenantId) return;

        setUploading(true);
        setError('');
        setNotice('');
        const body = new FormData();
        body.append('_token', csrfToken());
        body.append('tenant_id', tenantId);
        body.append('file', file);
        if (title.trim()) body.append('title', title.trim());

        try {
            const response = await fetch('/api/documents', {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                body,
            });
            if (response.status === 401) {
                onUnauthorized();
                return;
            }
            if (!response.ok) throw new Error(await errorMessage(response));

            const payload = await response.json();
            setNotice(`«${payload.document.title}» загружен. Индексация выполняется в фоне.`);
            setFile(null);
            setTitle('');
            await loadDocuments();
        } catch (requestError) {
            setError(requestError instanceof Error ? requestError.message : 'Не удалось загрузить документ.');
        } finally {
            setUploading(false);
        }
    }

    async function retryIndexing(document: DocumentItem) {
        setError('');
        try {
            const response = await fetch(`/api/documents/${document.id}/retry`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ tenant_id: Number(tenantId) }),
            });
            if (response.status === 401) {
                onUnauthorized();
                return;
            }
            if (!response.ok) throw new Error(await errorMessage(response));
            await loadDocuments();
        } catch (requestError) {
            setError(requestError instanceof Error ? requestError.message : 'Не удалось повторить индексацию.');
        }
    }

    async function deleteDocument(document: DocumentItem) {
        if (!window.confirm(`Удалить документ «${document.title}» и его индекс?`)) return;

        setError('');
        try {
            const response = await fetch(
                `/api/documents/${document.id}?tenant_id=${encodeURIComponent(tenantId)}`,
                {
                    method: 'DELETE',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                },
            );
            if (response.status === 401) {
                onUnauthorized();
                return;
            }
            if (!response.ok) throw new Error(await errorMessage(response));
            setNotice(`«${document.title}» удалён.`);
            await loadDocuments();
        } catch (requestError) {
            setError(requestError instanceof Error ? requestError.message : 'Не удалось удалить документ.');
        }
    }

    if (session.tenants.length === 0) {
        return (
            <Alert severity="warning">
                Для аккаунта пока не настроено рабочее пространство. Создайте его командой
                {' '}<code>php artisan workspace:admin</code>.
            </Alert>
        );
    }

    return (
        <Stack spacing={3}>
            <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ xs: 'stretch', sm: 'center' }} spacing={2}>
                <Box>
                    <Typography variant="overline" color="primary">Smart RAG Knowledge Base</Typography>
                    <Typography variant="h4" component="h1" fontWeight={700}>Документы</Typography>
                    <Typography color="text.secondary">
                        Загрузка и асинхронная индексация корпоративных материалов.
                    </Typography>
                </Box>
                <Stack direction="row" spacing={1} alignItems="center">
                    <Typography variant="body2">{session.user.name}</Typography>
                    <Button onClick={() => void onLogout()} color="inherit">Выйти</Button>
                </Stack>
            </Stack>

            {session.tenants.length > 1 && (
                <TextField
                    select
                    label="Рабочее пространство"
                    value={tenantId}
                    onChange={(event) => setTenantId(event.target.value)}
                    sx={{ maxWidth: 420 }}
                >
                    {session.tenants.map((tenant) => (
                        <MenuItem key={tenant.id} value={String(tenant.id)}>{tenant.name}</MenuItem>
                    ))}
                </TextField>
            )}

            {error && <Alert severity="error" onClose={() => setError('')}>{error}</Alert>}
            {notice && <Alert severity="success" onClose={() => setNotice('')}>{notice}</Alert>}

            {canManage ? (
                <Paper component="form" onSubmit={uploadDocument} variant="outlined" sx={{ p: 3 }}>
                    <Stack spacing={2}>
                        <Box>
                            <Typography variant="h6" fontWeight={600}>Загрузить документ</Typography>
                            <Typography variant="body2" color="text.secondary">
                                Поддерживаются .txt, .md, PDF с текстовым слоем и .docx размером до 10 МБ.
                                Сканированные PDF без OCR пока не поддерживаются.
                            </Typography>
                        </Box>
                        <TextField
                            label="Название (необязательно)"
                            value={title}
                            onChange={(event) => setTitle(event.target.value)}
                            fullWidth
                        />
                        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} alignItems={{ xs: 'stretch', sm: 'center' }}>
                            <Button component="label" variant="outlined">
                                {file ? file.name : 'Выбрать документ'}
                                <input
                                    hidden
                                    type="file"
                                    accept=".txt,.md,.pdf,.docx,text/plain,text/markdown,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                                    onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                                />
                            </Button>
                            <Button type="submit" variant="contained" disabled={!file || uploading}>
                                {uploading ? 'Загружаем…' : 'Загрузить и индексировать'}
                            </Button>
                            {uploading && <CircularProgress size={22} />}
                        </Stack>
                    </Stack>
                </Paper>
            ) : (
                <Alert severity="info">У вас есть доступ для просмотра. Загружать документы могут владельцы и администраторы пространства.</Alert>
            )}

            <Paper variant="outlined" sx={{ p: { xs: 1, sm: 2 } }}>
                <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ px: 1, py: 1 }}>
                    <Typography variant="h6" fontWeight={600}>База знаний</Typography>
                    <Button onClick={() => void loadDocuments()} disabled={loading}>
                        {loading ? <CircularProgress size={18} /> : 'Обновить'}
                    </Button>
                </Stack>
                <Divider />
                {documents.length === 0 ? (
                    <Typography color="text.secondary" sx={{ p: 3 }}>
                        Документов пока нет. Загрузите первый файл, чтобы начать индексирование.
                    </Typography>
                ) : (
                    <List disablePadding>
                        {documents.map((document, index) => (
                            <Box key={document.id}>
                                {index > 0 && <Divider component="li" />}
                                <ListItem alignItems="flex-start" sx={{ py: 2 }}>
                                    <ListItemAvatar>
                                        <Avatar>{document.title.slice(0, 1).toUpperCase()}</Avatar>
                                    </ListItemAvatar>
                                    <ListItemText
                                        primary={
                                            <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap">
                                                <Typography fontWeight={600}>{document.title}</Typography>
                                                <Chip size="small" label={statusLabels[document.index_status]} color={statusColors[document.index_status]} />
                                            </Stack>
                                        }
                                        secondary={`${document.source_type} · ${document.chunk_count} чанков · ${new Date(document.updated_at).toLocaleString('ru-RU')}`}
                                    />
                                    {canManage && document.index_status === 'failed' && (
                                        <Button size="small" onClick={() => void retryIndexing(document)}>Повторить</Button>
                                    )}
                                    {canManage && (
                                        <Button size="small" color="error" onClick={() => void deleteDocument(document)}>Удалить</Button>
                                    )}
                                </ListItem>
                            </Box>
                        ))}
                    </List>
                )}
            </Paper>

            <ChatPanel tenantId={tenantId} onUnauthorized={onUnauthorized} />
        </Stack>
    );
}
