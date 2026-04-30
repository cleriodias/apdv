<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/_bootstrap.php';

try {
    if ($requestMethod !== 'POST') {
        json_response(['ok' => false, 'error' => 'Metodo nao permitido.'], 405);
    }

    $payload = input_json();
    $email = mb_strtolower(trim((string) ($payload['email'] ?? '')));
    $password = (string) ($payload['password'] ?? '');

    if ($email === '' || $password === '') {
        json_response(['ok' => false, 'error' => 'Informe e-mail e senha.'], 422);
    }

    $conditions = user_activity_conditions('u');
    $conditions[] = 'lower(u.email) = :email';
    $whereSql = ' where ' . implode(' and ', $conditions);
    $userSelect = auth_user_select_sql('u');

    $statement = db()->prepare(
        "select
            {$userSelect}
         from users u
         {$whereSql}
         limit 1"
    );
    $statement->execute(['email' => $email]);
    $user = $statement->fetch();

    if (!$user || !password_verify($password, (string) ($user['password'] ?? ''))) {
        json_response(['ok' => false, 'error' => 'Credenciais invalidas.'], 401);
    }

    $role = auth_role_from_row($user);
    if ($role !== MASTER_ROLE) {
        json_response(['ok' => false, 'error' => 'Apenas usuarios MASTER podem acessar este app.'], 403);
    }

    $allowedUnitIds = fetch_allowed_unit_ids_for_user(
        (int) ($user['id'] ?? 0),
        isset($user['tb2_id']) ? (int) $user['tb2_id'] : null,
        auth_matrix_id_from_row($user)
    );

    if ($allowedUnitIds === []) {
        json_response(['ok' => false, 'error' => 'Nenhuma loja vinculada a este MASTER foi encontrada.'], 403);
    }

    json_response([
        'ok' => true,
        'token' => issue_master_session_token($user, $allowedUnitIds),
        'user' => [
            'id' => (int) ($user['id'] ?? 0),
            'name' => (string) ($user['name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'role' => $role,
            'role_label' => auth_role_label($role),
            'unit_id' => isset($user['tb2_id']) ? (int) $user['tb2_id'] : null,
            'allowed_units' => fetch_allowed_units_metadata($allowedUnitIds),
        ],
    ]);
} catch (Throwable $exception) {
    json_response(['ok' => false, 'error' => 'Falha ao processar o login.'], 500);
}
