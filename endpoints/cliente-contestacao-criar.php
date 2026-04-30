<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$payload = input_json();
if (
    isset($payload['sender_user_id'], $payload['recipient_user_id']) ||
    (($payload['action'] ?? '') === 'chat_send')
) {
    require __DIR__ . '/mobile/chat/index.php';
}

unsupported_orders_endpoint();
