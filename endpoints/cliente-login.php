<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$payload = input_json();
if (($payload['action'] ?? '') === 'snapshot') {
    require __DIR__ . '/mobile/chat/index.php';
}

unsupported_orders_endpoint();
