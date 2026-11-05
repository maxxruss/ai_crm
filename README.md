# Smart RAG Knowledge Base

Корпоративная база знаний: Laravel 12 / PHP 8.3, React 18 + MUI + Vite, PostgreSQL 16 с pgvector, Redis/Horizon и Laravel Reverb. Есть вход по сессии, tenant-scoped загрузка и индексация документов, поиск по локальным embeddings, RAG-чат с историей диалогов и ссылками на найденные фрагменты. Потоковый вывод через WebSocket ещё не подключён.

## Запуск локально

1. Задайте Laravel encryption key и запустите контейнеры: `APP_KEY="base64:$(openssl rand -base64 32)" docker compose up --build`.
2. Откройте `http://localhost:8080`; проверка API — `http://localhost:8080/api/health`.

Compose включает Laravel PHP-FPM, Nginx, PostgreSQL 16 + pgvector, Redis, Horizon, Reverb и Vite dev server. Веб-приложение и dev-сервер доступны через Nginx; PostgreSQL и Redis привязаны только к loopback-интерфейсу хоста.

Если порт `8080` уже занят, выберите свободный порт для Nginx и задайте тот же адрес приложения, чтобы Vite и WebSocket HMR использовали правильный origin:

```bash
WEB_PORT=8081 APP_URL=http://localhost:8081 docker compose up --build
```

Для production необходимо использовать отдельные сильные секреты, TLS-терминацию, закрытые порты БД/Redis, ограничение сетевого доступа, резервное копирование и production-настройки PHP/Nginx. Текущий Compose предназначен для локального старта и разработки, а не для непосредственного публичного деплоя.

## Архитектурные решения

- Используется `pgvector/pgvector:pg16`, чтобы гарантировать сочетание PostgreSQL 16 и pgvector.
- Эмбеддинги хранятся на уровне `document_chunks`, а не всего документа: каждый смысловой фрагмент индексируется отдельно.
- Локальная `intfloat/multilingual-e5-base` возвращает 768-мерные векторы; миграция создаёт `vector(768)` и cosine HNSW индекс. Размерность нельзя менять без миграции и переиндексации.
- Laravel Reverb подключён как WebSocket broadcaster; пользовательские события должны публиковаться только в приватные каналы с серверной авторизацией.
- Секреты AI-провайдера передаются через переменные окружения и не коммитятся.
- Используется Laravel 12 вместо первоначально запрошенного Laravel 11: Composer блокирует установку Laravel 11 из-за опубликованных security advisories для всех доступных версий этой ветки. Для рабочего корпоративного проекта оставлять известные уязвимости или отключать проверку безопасности не следует.

## Схема и индексирование

Миграции включают pgvector, tenants/users/memberships, документы, чанки, диалоги и сообщения. В чанке есть копия `tenant_id` и составной внешний ключ к документу; семантический поиск всегда ограничивается tenant и только готовыми документами.

- Применить схему: `docker compose exec app php artisan migrate --force`
- Создать первого владельца и workspace (команда запросит пароль без отображения): `docker compose exec app php artisan workspace:admin admin@example.com --name="Владелец" --tenant="Моя компания"`
- Войти в интерфейс на `http://localhost:8080` (или на настроенном `WEB_PORT`); загружать документы могут только `owner` и `admin`.
- Загрузка поддерживает UTF-8 `.txt`/`.md`, PDF с текстовым слоем и `.docx` до 10 МБ. Сканированные PDF без OCR пока не поддерживаются.
- В интерфейсе «Чат по базе знаний» задайте вопрос после того, как документ получит статус «Готов». Если релевантных фрагментов нет, приложение откажется от ответа; порог регулируется `RAG_MAX_COSINE_DISTANCE` (по умолчанию `0.55`).
- Поставить существующий документ в очередь: `docker compose exec app php artisan documents:index <document-id>`
- Проверить поиск по tenant: `docker compose exec app php artisan rag:search <tenant-id> "вопрос"`

Для ответа найденные фрагменты документа передаются в GigaChat; embeddings при этом остаются локальными. Потоковый ответ через приватные WebSocket-каналы — следующий этап.

## Локальные embeddings и проверка GigaChat

GigaChat используется для генерации ответов, а векторизацию выполняет локальный `intfloat/multilingual-e5-base` через Hugging Face TEI. Модель поддерживает русский язык и возвращает 768-мерные векторы; сервис добавляет `passage:` для фрагментов документов и `query:` для вопросов. Текст не отправляется в Sber для векторизации. При первом запуске контейнер скачает около 1,1 ГБ файлов модели в volume `embeddings_model_cache`; на CPU загрузка и запуск могут занять несколько минут.

Для персонального доступа задайте в локальном `.env` `GIGACHAT_AUTH_KEY` (Authorization key из кабинета) и `GIGACHAT_SCOPE=GIGACHAT_API_PERS`. OAuth-токен получается приложением и кешируется с запасом до истечения срока; сам ключ не коммитится и не выводится командой проверки.

Если API отвечает ошибкой `self-signed certificate in certificate chain`, скачайте рекомендованные Сбером сертификаты НУЦ Минцифры и поместите bundle `russian_trusted_root_ca_pem.crt` в `docker/certs/`. Compose монтирует его в PHP и worker; TLS-проверка остаётся включённой. Источник и инструкция — [документация Сбера по сертификатам](https://developers.sber.ru/docs/ru/gigachat/certificates).

После готовности embeddings-контейнера запустите `docker compose exec app php artisan gigachat:check`. Команда отдельно проверит локальную векторизацию (ожидаем 768 измерений) и короткий запрос к GigaChat.
