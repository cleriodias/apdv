<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/_bootstrap.php';

const CHAT_ONLINE_WINDOW_MINUTES = 2;
const CHAT_MESSAGE_LIMIT = 80;
const CHAT_MAX_IMAGE_BYTES = 4194304;

const CHAT_ROLE_LABELS = [
    0 => 'MASTER',
    1 => 'GERENTE',
    2 => 'SUB-GERENTE',
    3 => 'CAIXA',
    4 => 'LANCHONETE',
    5 => 'FUNCIONARIO',
    6 => 'CLIENTE',
];

function chat_role_label(int $role): string
{
    return CHAT_ROLE_LABELS[$role] ?? '---';
}

function chat_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
}

function chat_iso(?string $value): ?string
{
    if (!$value) {
        return null;
    }

    try {
        return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
    } catch (Throwable) {
        return $value;
    }
}

function chat_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'apdv.azurewebsites.net');

    return "{$scheme}://{$host}";
}

function chat_format_user(array $row, bool $isOnline): array
{
    $role = (int) ($row['role'] ?? 0);

    return [
        'id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['name'] ?? ''),
        'role' => $role,
        'role_label' => chat_role_label($role),
        'unit_id' => $row['unit_id'] === null ? null : (int) $row['unit_id'],
        'unit_name' => (string) ($row['unit_name'] ?? 'Sem loja ativa'),
        'last_seen_at' => chat_iso($row['last_seen_at'] ?? null),
        'is_online' => $isOnline,
        'unread_count' => (int) ($row['unread_count'] ?? 0),
        'last_message_preview' => (string) ($row['last_message_preview'] ?? ''),
    ];
}

function chat_message_preview(string $message): string
{
    $message = preg_replace('/\[image:[\s\S]*?\]/i', 'Foto', $message) ?? $message;
    $message = trim(preg_replace('/\s+/u', ' ', $message) ?? '');

    if (mb_strlen($message, 'UTF-8') <= 45) {
        return $message;
    }

    return mb_substr($message, 0, 42, 'UTF-8') . '...';
}

function chat_unread_counts(int $viewerId): array
{
    if ($viewerId <= 0) {
        return [];
    }

    $statement = db()->prepare(
        'select sender_id, count(*) as total
         from tb22_chat_mensagens
         where recipient_id = :viewer_id and read_at is null
         group by sender_id'
    );
    $statement->execute(['viewer_id' => $viewerId]);

    $counts = [];
    foreach ($statement->fetchAll() as $row) {
        $counts[(int) $row['sender_id']] = (int) $row['total'];
    }

    return $counts;
}

function chat_latest_previews(int $viewerId): array
{
    if ($viewerId <= 0) {
        return [];
    }

    $statement = db()->prepare(
        'select sender_id, recipient_id, message
         from tb22_chat_mensagens
         where sender_id = :viewer_id or recipient_id = :viewer_id
         order by id desc
         limit 300'
    );
    $statement->execute(['viewer_id' => $viewerId]);

    $previews = [];
    foreach ($statement->fetchAll() as $row) {
        $contactId = (int) $row['sender_id'] === $viewerId ? (int) $row['recipient_id'] : (int) $row['sender_id'];
        if (isset($previews[$contactId])) {
            continue;
        }

        $previews[$contactId] = chat_message_preview((string) $row['message']);
    }

    return $previews;
}

function chat_users(?int $viewerId, array $allowedUnitIds): array
{
    $onlineSince = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))
        ->modify('-' . CHAT_ONLINE_WINDOW_MINUTES . ' minutes')
        ->format('Y-m-d H:i:s');
    [$onlineUnitSql, $onlineUnitParams] = build_unit_scope_condition($allowedUnitIds, 'ou.active_unit_id', 'chat_online_unit');

    $onlineStatement = db()->prepare(
        'select
            u.id,
            u.name,
            coalesce(u.funcao_original, u.funcao, ou.active_role) as role,
            ou.active_unit_id as unit_id,
            coalesce(un.tb2_nome, "Sem loja ativa") as unit_name,
            ou.last_seen_at
         from tb21_usuarios_online ou
         inner join users u on u.id = ou.user_id
         left join tb2_unidades un on un.tb2_id = ou.active_unit_id
         inner join (
            select user_id, max(last_seen_at) as last_seen_at
            from tb21_usuarios_online
            where last_seen_at >= :online_since
            group by user_id
         ) latest on latest.user_id = ou.user_id and latest.last_seen_at = ou.last_seen_at
         where coalesce(u.funcao_original, u.funcao, ou.active_role) in (0, 1, 2, 3, 4)
           and ' . $onlineUnitSql . '
         order by u.name asc'
    );
    $onlineStatement->execute([
        'online_since' => $onlineSince,
        ...$onlineUnitParams,
    ]);
    $onlineRows = $onlineStatement->fetchAll();
    $onlineIds = array_map(static fn (array $row): int => (int) $row['id'], $onlineRows);

    [$offlineUnitSql, $offlineUnitParams] = build_unit_scope_condition($allowedUnitIds, 'coalesce(u.tb2_id, pivot_units.tb2_id)', 'chat_offline_unit');
    $offlineSql = 'select
            u.id,
            u.name,
            coalesce(u.funcao_original, u.funcao) as role,
            coalesce(u.tb2_id, pivot_units.tb2_id) as unit_id,
            coalesce(primary_unit.tb2_nome, pivot_units.tb2_nome, "Sem loja ativa") as unit_name,
            null as last_seen_at
        from users u
            left join tb2_unidades primary_unit on primary_unit.tb2_id = u.tb2_id
        left join (
            select pivot.user_id, min(pivot.tb2_id) as tb2_id, min(unit.tb2_nome) as tb2_nome
            from tb2_unidade_user pivot
            left join tb2_unidades unit on unit.tb2_id = pivot.tb2_id
            group by pivot.user_id
        ) pivot_units on pivot_units.user_id = u.id
        where coalesce(u.funcao_original, u.funcao) in (0, 1, 2, 3, 4)
          and ' . $offlineUnitSql;
    $params = $offlineUnitParams;

    if (!empty($onlineIds)) {
        [$placeholders, $onlineIdParams] = build_sql_placeholders($onlineIds, 'chat_offline_user');
        $placeholdersSql = implode(',', $placeholders);
        $offlineSql .= " and u.id not in ({$placeholdersSql})";
        $params = [
            ...$params,
            ...$onlineIdParams,
        ];
    }

    $offlineSql .= ' order by u.name asc';

    $offlineStatement = db()->prepare($offlineSql);
    $offlineStatement->execute($params);
    $offlineRows = $offlineStatement->fetchAll();

    $unreadCounts = $viewerId ? chat_unread_counts($viewerId) : [];
    $previews = $viewerId ? chat_latest_previews($viewerId) : [];

    $attachMeta = static function (array $row) use ($unreadCounts, $previews): array {
        $id = (int) $row['id'];
        $row['unread_count'] = $unreadCounts[$id] ?? 0;
        $row['last_message_preview'] = $previews[$id] ?? '';

        return $row;
    };

    return [
        'online' => array_map(static fn (array $row): array => chat_format_user($attachMeta($row), true), $onlineRows),
        'offline' => array_map(static fn (array $row): array => chat_format_user($attachMeta($row), false), $offlineRows),
    ];
}

function chat_find_user(int $userId, array $allowedUnitIds, bool $preferPresence = true): ?array
{
    if ($userId <= 0) {
        return null;
    }

    if ($preferPresence) {
        [$presenceUnitSql, $presenceUnitParams] = build_unit_scope_condition($allowedUnitIds, 'ou.active_unit_id', 'chat_find_presence');
        $statement = db()->prepare(
            'select
                u.id,
                u.name,
                coalesce(u.funcao_original, u.funcao, ou.active_role) as role,
                ou.active_unit_id as unit_id,
                coalesce(un.tb2_nome, "Sem loja ativa") as unit_name,
                ou.last_seen_at
             from users u
             left join tb21_usuarios_online ou on ou.user_id = u.id
             left join tb2_unidades un on un.tb2_id = ou.active_unit_id
             where u.id = :user_id
               and ' . $presenceUnitSql . '
             order by ou.last_seen_at desc
             limit 1'
        );
        $params = [
            'user_id' => $userId,
            ...$presenceUnitParams,
        ];
    } else {
        [$offlineUnitSql, $offlineUnitParams] = build_unit_scope_condition($allowedUnitIds, 'coalesce(u.tb2_id, pivot_units.tb2_id)', 'chat_find_offline');
        $statement = db()->prepare(
            'select
                u.id,
                u.name,
                coalesce(u.funcao_original, u.funcao) as role,
                coalesce(u.tb2_id, pivot_units.tb2_id) as unit_id,
                coalesce(un.tb2_nome, pivot_units.tb2_nome, "Sem loja ativa") as unit_name,
                null as last_seen_at
             from users u
             left join tb2_unidades un on un.tb2_id = u.tb2_id
             left join (
                select pivot.user_id, min(pivot.tb2_id) as tb2_id, min(unit.tb2_nome) as tb2_nome
                from tb2_unidade_user pivot
                left join tb2_unidades unit on unit.tb2_id = pivot.tb2_id
                group by pivot.user_id
             ) pivot_units on pivot_units.user_id = u.id
             where u.id = :user_id
               and ' . $offlineUnitSql . '
             limit 1'
        );
        $params = [
            'user_id' => $userId,
            ...$offlineUnitParams,
        ];
    }

    $statement->execute($params);
    $row = $statement->fetch();

    return $row ? chat_format_user($row, (bool) ($row['last_seen_at'] ?? false)) : null;
}

function chat_messages(int $viewerId, int $contactId): array
{
    if ($viewerId <= 0 || $contactId <= 0) {
        return [];
    }

    db()->prepare(
        'update tb22_chat_mensagens
         set read_at = :read_at
         where sender_id = :contact_id and recipient_id = :viewer_id and read_at is null'
    )->execute([
        'read_at' => chat_now(),
        'contact_id' => $contactId,
        'viewer_id' => $viewerId,
    ]);

    $statement = db()->prepare(
        'select
            m.id,
            m.sender_id,
            m.recipient_id,
            m.message,
            m.sender_role,
            m.sender_unit_id,
            sender.name as sender_name,
            m.created_at,
            m.read_at
         from tb22_chat_mensagens m
         inner join users sender on sender.id = m.sender_id
         where (m.sender_id = :viewer_a and m.recipient_id = :contact_a)
            or (m.sender_id = :contact_b and m.recipient_id = :viewer_b)
         order by m.id desc
         limit ' . CHAT_MESSAGE_LIMIT
    );
    $statement->execute([
        'viewer_a' => $viewerId,
        'contact_a' => $contactId,
        'contact_b' => $contactId,
        'viewer_b' => $viewerId,
    ]);

    $messages = array_reverse($statement->fetchAll());

    return array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'sender_id' => (int) $row['sender_id'],
        'recipient_id' => (int) $row['recipient_id'],
        'message' => (string) $row['message'],
        'sender_name' => (string) $row['sender_name'],
        'sent_at' => chat_iso($row['created_at'] ?? null),
        'read_at' => chat_iso($row['read_at'] ?? null),
        'sender_role' => (int) $row['sender_role'],
        'sender_role_label' => chat_role_label((int) $row['sender_role']),
        'is_mine' => (int) $row['sender_id'] === $viewerId,
    ], $messages);
}

function chat_store_image(array $payload): ?string
{
    $base64 = trim((string) ($payload['photo_base64'] ?? ''));
    if ($base64 === '') {
        return null;
    }

    $mimeType = (string) ($payload['photo_type'] ?? 'image/jpeg');
    $extension = strtolower((string) ($payload['photo_ext'] ?? 'jpg'));
    $allowed = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    if (!isset($allowed[$extension]) || $allowed[$extension] !== $mimeType) {
        json_response(['ok' => false, 'error' => 'Formato da foto invalido.'], 422);
    }

    $binary = base64_decode($base64, true);
    if ($binary === false || strlen($binary) > CHAT_MAX_IMAGE_BYTES) {
        json_response(['ok' => false, 'error' => 'Foto invalida ou muito grande.'], 422);
    }

    $relativeDir = 'uploads/chat/' . (new DateTimeImmutable('now'))->format('Y/m');
    $absoluteDir = dirname(__DIR__, 2) . '/' . $relativeDir;

    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        json_response(['ok' => false, 'error' => 'Nao foi possivel salvar a foto.'], 500);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
    $path = $absoluteDir . '/' . $filename;

    if (file_put_contents($path, $binary) === false) {
        json_response(['ok' => false, 'error' => 'Nao foi possivel gravar a foto.'], 500);
    }

    return '/endpoints/' . $relativeDir . '/' . $filename;
}

function chat_snapshot(int $viewerId, array $allowedUnitIds, ?int $selectedUserId = null): array
{
    $users = chat_users($viewerId, $allowedUnitIds);
    $allUsers = array_merge($users['online'], $users['offline']);
    $availableIds = array_map(static fn (array $user): int => (int) $user['id'], $allUsers);

    if ($viewerId > 0) {
        $availableIds = array_values(array_filter($availableIds, static fn (int $id): bool => $id !== $viewerId));
    }

    if ($selectedUserId === null || !in_array($selectedUserId, $availableIds, true)) {
        $selectedUserId = $availableIds[0] ?? null;
    }

    return [
        'ok' => true,
        'currentUser' => chat_find_user($viewerId, $allowedUnitIds) ?: chat_find_user($viewerId, $allowedUnitIds, false),
        'onlineUsers' => array_values(array_filter($users['online'], static fn (array $user): bool => (int) $user['id'] !== $viewerId)),
        'offlineUsers' => array_values(array_filter($users['offline'], static fn (array $user): bool => (int) $user['id'] !== $viewerId)),
        'selectedUserId' => $selectedUserId,
        'messages' => $selectedUserId ? chat_messages($viewerId, $selectedUserId) : [],
    ];
}

try {
    $auth = require_master_session();
    $viewerId = (int) ($auth['id'] ?? 0);
    $allowedUnitIds = $auth['allowed_unit_ids'];

    if ($requestMethod === 'GET') {
        $selectedUserId = isset($_GET['selected'])
            ? (int) $_GET['selected']
            : (isset($_GET['c'])
                ? (int) $_GET['c']
                : (isset($_GET['selected_user_id']) ? (int) $_GET['selected_user_id'] : null));

        json_response(chat_snapshot($viewerId, $allowedUnitIds, $selectedUserId));
    }

    $payload = input_json();
    if (($payload['action'] ?? '') === 'snapshot') {
        $selectedUserId = isset($payload['selected'])
            ? (int) $payload['selected']
            : (isset($payload['c'])
                ? (int) $payload['c']
                : (isset($payload['selected_id']) ? (int) $payload['selected_id'] : null));

        json_response(chat_snapshot($viewerId, $allowedUnitIds, $selectedUserId));
    }

    if ($requestMethod !== 'POST') {
        json_response(['ok' => false, 'error' => 'Metodo nao permitido.'], 405);
    }

    $senderId = $viewerId;
    $recipientId = (int) ($payload['recipient_user_id'] ?? 0);
    $text = trim((string) ($payload['message'] ?? ''));
    $imageUrl = chat_store_image($payload);

    if ($senderId <= 0 || $recipientId <= 0 || $senderId === $recipientId) {
        json_response(['ok' => false, 'error' => 'Remetente ou destinatario invalido.'], 422);
    }

    if ($text === '' && !$imageUrl) {
        json_response(['ok' => false, 'error' => 'Digite uma mensagem ou tire uma foto antes de enviar.'], 422);
    }

    $sender = chat_find_user($senderId, $allowedUnitIds) ?: chat_find_user($senderId, $allowedUnitIds, false);
    $recipient = chat_find_user($recipientId, $allowedUnitIds, false);

    if (!$sender || !$recipient) {
        json_response(['ok' => false, 'error' => 'Usuario nao encontrado.'], 404);
    }

    $message = $text;
    if ($imageUrl) {
        $message = trim($message . "\n[image:{$imageUrl}]");
    }

    $now = chat_now();
    $insert = db()->prepare(
        'insert into tb22_chat_mensagens
            (sender_id, recipient_id, sender_role, sender_unit_id, message, created_at, updated_at)
         values
            (:sender_id, :recipient_id, :sender_role, :sender_unit_id, :message, :created_at, :updated_at)'
    );

    $insert->execute([
        'sender_id' => $senderId,
        'recipient_id' => $recipientId,
        'sender_role' => (int) $sender['role'],
        'sender_unit_id' => $sender['unit_id'],
        'message' => $message,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    json_response(chat_snapshot($senderId, $allowedUnitIds, $recipientId));
} catch (Throwable $exception) {
    json_response(['ok' => false, 'error' => 'Falha ao processar o chat.'], 500);
}
