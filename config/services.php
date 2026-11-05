<?php

return [
    'gigachat' => [
        'auth_key' => env('GIGACHAT_AUTH_KEY'),
        'scope' => env('GIGACHAT_SCOPE', 'GIGACHAT_API_PERS'),
        'token_url' => env('GIGACHAT_TOKEN_URL', 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth'),
        'base_url' => rtrim((string) env('GIGACHAT_BASE_URL', 'https://api.giga.chat/v1'), '/'),
        'chat_model' => env('GIGACHAT_CHAT_MODEL', 'GigaChat-2-Lite'),
        'ca_bundle' => env(
            'GIGACHAT_CA_BUNDLE',
            '/etc/gigachat/certs/russian_trusted_root_ca_pem.crt',
        ),
        'timeout' => (int) env('GIGACHAT_TIMEOUT', 45),
    ],
    'local_embeddings' => [
        'url' => env('LOCAL_EMBEDDING_URL', 'http://embeddings:80'),
        'model' => env('LOCAL_EMBEDDING_MODEL', 'intfloat/multilingual-e5-base'),
        'dimensions' => (int) env('LOCAL_EMBEDDING_DIMENSIONS', 768),
        'timeout' => (int) env('LOCAL_EMBEDDING_TIMEOUT', 60),
    ],
    'rag' => [
        'result_limit' => (int) env('RAG_RESULT_LIMIT', 5),
        'max_cosine_distance' => (float) env('RAG_MAX_COSINE_DISTANCE', 0.55),
    ],
];
