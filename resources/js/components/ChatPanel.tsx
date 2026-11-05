import { useEffect, useRef, useState, type FormEvent } from 'react';
import {
    Alert,
    Avatar,
    Box,
    Button,
    CircularProgress,
    Divider,
    List,
    ListItemButton,
    ListItemText,
    Paper,
    Stack,
    TextField,
    Typography,
} from '@mui/material';

interface Citation {
    ref: string;
    document_id: number;
    document_title: string;
    chunk_index: number;
    excerpt: string;
    distance: number;
}

interface ChatMessage {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    citations: Citation[];
    created_at: string;
}

interface ConversationSummary {
    id: number;
    title: string | null;
    updated_at: string;
}

interface ChatPanelProps {
    tenantId: string;
    onUnauthorized: () => void;
}

async function responseError(response: Response): Promise<string> {
    const payload = await response.json().catch(() => ({}));
    if (payload.errors) {
        const firstError = Object.values(payload.errors).flat()[0];
        if (typeof firstError === 'string') return firstError;
    }

    return payload.message ?? `Ошибка запроса (${response.status}).`;
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function formattedDate(value: string): string {
    return new Date(value).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}

export default function ChatPanel({ tenantId, onUnauthorized }: ChatPanelProps) {
    const [conversations, setConversations] = useState<ConversationSummary[]>([]);
    const [conversationId, setConversationId] = useState<number | null>(null);
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [question, setQuestion] = useState('');
    const [error, setError] = useState('');
    const [loadingConversations, setLoadingConversations] = useState(false);
    const [loadingMessages, setLoadingMessages] = useState(false);
    const [sending, setSending] = useState(false);
    const endOfMessages = useRef<HTMLDivElement>(null);

    useEffect(() => {
        setConversationId(null);
        setMessages([]);
        setConversations([]);
        setError('');

        if (!tenantId) return undefined;

        let cancelled = false;
        setLoadingConversations(true);
        void fetch(`/api/conversations?tenant_id=${encodeURIComponent(tenantId)}`, {
            headers: { Accept: 'application/json' },
        })
            .then(async (response) => {
                if (response.status === 401) {
                    onUnauthorized();
                    return null;
                }
                if (!response.ok) throw new Error(await responseError(response));
                return (await response.json()) as { conversations: ConversationSummary[] };
            })
            .then((payload) => {
                if (!cancelled && payload) setConversations(payload.conversations);
            })
            .catch((requestError: unknown) => {
                if (!cancelled) {
                    setError(requestError instanceof Error ? requestError.message : 'Не удалось загрузить историю чатов.');
                }
            })
            .finally(() => {
                if (!cancelled) setLoadingConversations(false);
            });

        return () => {
            cancelled = true;
        };
    }, [tenantId, onUnauthorized]);

    useEffect(() => {
        endOfMessages.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }, [messages, sending]);

    async function selectConversation(id: number) {
        setConversationId(id);
        setMessages([]);
        setError('');
        setLoadingMessages(true);

        try {
            const response = await fetch(
                `/api/conversations/${id}?tenant_id=${encodeURIComponent(tenantId)}`,
                { headers: { Accept: 'application/json' } },
            );
            if (response.status === 401) {
                onUnauthorized();
                return;
            }
            if (!response.ok) throw new Error(await responseError(response));
            const payload = (await response.json()) as { messages: ChatMessage[] };
            setMessages(payload.messages);
        } catch (requestError) {
            setError(requestError instanceof Error ? requestError.message : 'Не удалось открыть историю чата.');
        } finally {
            setLoadingMessages(false);
        }
    }

    function startNewConversation() {
        setConversationId(null);
        setMessages([]);
        setQuestion('');
        setError('');
    }

    async function sendQuestion(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const prompt = question.trim();
        if (!prompt || !tenantId || sending) return;

        const optimisticId = -Date.now();
        setSending(true);
        setQuestion('');
        setError('');
        setMessages((current) => [
            ...current,
            {
                id: optimisticId,
                role: 'user',
                content: prompt,
                citations: [],
                created_at: new Date().toISOString(),
            },
        ]);

        try {
            const body: { tenant_id: number; question: string; conversation_id?: number } = {
                tenant_id: Number(tenantId),
                question: prompt,
            };
            if (conversationId !== null) body.conversation_id = conversationId;

            const response = await fetch('/api/chat', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(body),
            });
            if (response.status === 401) {
                onUnauthorized();
                return;
            }
            if (!response.ok) throw new Error(await responseError(response));

            const payload = (await response.json()) as {
                conversation: ConversationSummary;
                user_message: ChatMessage;
                assistant_message: ChatMessage;
            };
            setConversationId(payload.conversation.id);
            setMessages((current) => [
                ...current.filter((message) => message.id !== optimisticId),
                payload.user_message,
                payload.assistant_message,
            ]);
            setConversations((current) => [
                payload.conversation,
                ...current.filter((conversation) => conversation.id !== payload.conversation.id),
            ]);
        } catch (requestError) {
            setMessages((current) => current.filter((message) => message.id !== optimisticId));
            setQuestion(prompt);
            setError(requestError instanceof Error ? requestError.message : 'Не удалось отправить вопрос.');
        } finally {
            setSending(false);
        }
    }

    return (
        <Paper variant="outlined" sx={{ p: { xs: 1.5, sm: 2.5 } }}>
            <Stack spacing={2}>
                <Box>
                    <Typography variant="h5" component="h2" fontWeight={700}>Чат по базе знаний</Typography>
                    <Typography variant="body2" color="text.secondary">
                        Ответы строятся по проиндексированным документам. Найденные фрагменты появятся под ответом.
                    </Typography>
                </Box>

                {error && <Alert severity="error" onClose={() => setError('')}>{error}</Alert>}

                <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} alignItems="stretch">
                    <Paper variant="outlined" sx={{ width: { xs: '100%', md: 260 }, flexShrink: 0 }}>
                        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ p: 1.5 }}>
                            <Typography fontWeight={600}>История</Typography>
                            <Button size="small" onClick={startNewConversation}>Новый чат</Button>
                        </Stack>
                        <Divider />
                        {loadingConversations ? (
                            <Stack alignItems="center" sx={{ p: 2 }}><CircularProgress size={22} /></Stack>
                        ) : conversations.length === 0 ? (
                            <Typography variant="body2" color="text.secondary" sx={{ p: 2 }}>
                                Диалогов пока нет.
                            </Typography>
                        ) : (
                            <List dense disablePadding sx={{ maxHeight: { md: 360 }, overflowY: 'auto' }}>
                                {conversations.map((conversation) => (
                                    <ListItemButton
                                        key={conversation.id}
                                        selected={conversationId === conversation.id}
                                        onClick={() => void selectConversation(conversation.id)}
                                    >
                                        <ListItemText
                                            primary={conversation.title || 'Диалог'}
                                            secondary={formattedDate(conversation.updated_at)}
                                            primaryTypographyProps={{ noWrap: true }}
                                        />
                                    </ListItemButton>
                                ))}
                            </List>
                        )}
                    </Paper>

                    <Stack spacing={1.5} sx={{ flex: 1, minWidth: 0 }}>
                        <Paper
                            variant="outlined"
                            aria-live="polite"
                            sx={{ p: { xs: 1.5, sm: 2 }, minHeight: 280, maxHeight: 520, overflowY: 'auto' }}
                        >
                            {loadingMessages ? (
                                <Stack alignItems="center" justifyContent="center" sx={{ minHeight: 240 }}>
                                    <CircularProgress />
                                </Stack>
                            ) : messages.length === 0 ? (
                                <Stack alignItems="center" justifyContent="center" sx={{ minHeight: 240 }} spacing={1}>
                                    <Avatar sx={{ bgcolor: 'primary.main' }}>?</Avatar>
                                    <Typography color="text.secondary" textAlign="center">
                                        Задайте вопрос по ТЗ или другим готовым документам.
                                    </Typography>
                                </Stack>
                            ) : (
                                <Stack spacing={2}>
                                    {messages.map((message) => (
                                        <Box
                                            key={message.id}
                                            sx={{
                                                alignSelf: message.role === 'user' ? 'flex-end' : 'stretch',
                                                maxWidth: message.role === 'user' ? '88%' : '100%',
                                                minWidth: 0,
                                            }}
                                        >
                                            <Paper
                                                variant={message.role === 'user' ? 'elevation' : 'outlined'}
                                                elevation={message.role === 'user' ? 0 : undefined}
                                                sx={{
                                                    p: 1.5,
                                                    bgcolor: message.role === 'user' ? 'action.hover' : 'background.paper',
                                                }}
                                            >
                                                <Typography
                                                    variant="body2"
                                                    sx={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}
                                                >
                                                    {message.content}
                                                </Typography>
                                            </Paper>
                                            {message.role === 'assistant' && message.citations.length > 0 && (
                                                <Stack spacing={1} sx={{ mt: 1 }}>
                                                    <Typography variant="caption" color="text.secondary">Источники</Typography>
                                                    {message.citations.map((citation) => (
                                                        <Paper key={citation.ref} variant="outlined" sx={{ p: 1.25 }}>
                                                            <Typography variant="caption" color="primary.main" fontWeight={700}>
                                                                [{citation.ref}] {citation.document_title} · фрагмент {citation.chunk_index + 1}
                                                            </Typography>
                                                            <Typography
                                                                variant="body2"
                                                                color="text.secondary"
                                                                sx={{ mt: 0.5, whiteSpace: 'pre-wrap' }}
                                                            >
                                                                {citation.excerpt}
                                                            </Typography>
                                                        </Paper>
                                                    ))}
                                                </Stack>
                                            )}
                                        </Box>
                                    ))}
                                    {sending && (
                                        <Stack direction="row" alignItems="center" spacing={1}>
                                            <CircularProgress size={18} />
                                            <Typography variant="body2" color="text.secondary">Ищу в документах и готовлю ответ…</Typography>
                                        </Stack>
                                    )}
                                    <div ref={endOfMessages} />
                                </Stack>
                            )}
                        </Paper>

                        <Stack component="form" onSubmit={sendQuestion} direction={{ xs: 'column', sm: 'row' }} spacing={1}>
                            <TextField
                                value={question}
                                onChange={(event) => setQuestion(event.target.value)}
                                placeholder="Например: какие сроки и критерии приемки указаны в ТЗ?"
                                inputProps={{ maxLength: 2000, 'aria-label': 'Вопрос по документам' }}
                                multiline
                                minRows={2}
                                maxRows={6}
                                fullWidth
                                disabled={sending || !tenantId}
                            />
                            <Button type="submit" variant="contained" disabled={!question.trim() || sending || !tenantId}>
                                {sending ? 'Отправляем…' : 'Спросить'}
                            </Button>
                        </Stack>
                    </Stack>
                </Stack>
            </Stack>
        </Paper>
    );
}
