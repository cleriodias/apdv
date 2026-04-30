<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const MASTER_ROLE = 0;
const ROLE_LABELS = [
    0 => 'MASTER',
    1 => 'GERENTE',
    2 => 'SUB-GERENTE',
    3 => 'CAIXA',
    4 => 'LANCHONETE',
    5 => 'FUNCIONARIO',
    6 => 'CLIENTE',
];

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: '';
    $database = getenv('DB_DATABASE') ?: '';
    $username = getenv('DB_USERNAME') ?: '';
    $password = getenv('DB_PASSWORD') ?: '';
    $sslCa = getenv('MYSQL_ATTR_SSL_CA') ?: null;

    if ($host === '' || $database === '' || $username === '' || $password === '') {
        json_response(['ok' => false, 'error' => 'Configuracao do banco incompleta.'], 500);
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    if ($sslCa) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
    }

    $pdo = new PDO(
        "mysql:host={$host};port=3306;dbname={$database};charset=utf8mb4",
        $username,
        $password,
        $options
    );

    return $pdo;
}

function input_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);

    if (is_array($data)) {
        return $data;
    }

    if (!empty($_POST) && is_array($_POST)) {
        return $_POST;
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
        parse_str($raw, $formData);
        return is_array($formData) ? $formData : [];
    }

    return [];
}

function table_columns(string $tableName): array
{
    static $cache = [];

    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $statement = db()->prepare(
        'select column_name
         from information_schema.columns
         where table_schema = database()
           and table_name = :table_name'
    );
    $statement->execute(['table_name' => $tableName]);

    $cache[$tableName] = array_values(array_filter(array_map(
        static function (array $row): string {
            foreach ($row as $value) {
                $column = trim((string) $value);
                if ($column !== '') {
                    return $column;
                }
            }

            return '';
        },
        $statement->fetchAll()
    )));

    return $cache[$tableName];
}

function table_exists(string $tableName): bool
{
    return table_columns($tableName) !== [];
}

function user_activity_conditions(string $alias = 'u'): array
{
    $columns = table_columns('users');
    $conditions = [];

    if (in_array('deleted_at', $columns, true)) {
        $conditions[] = "{$alias}.deleted_at is null";
    }

    foreach (['status', 'ativo', 'is_active', 'active', 'tb3_status'] as $column) {
        if (in_array($column, $columns, true)) {
            $conditions[] = "coalesce({$alias}.{$column}, 1) = 1";
            break;
        }
    }

    return $conditions;
}

function auth_role_label(int $role): string
{
    return ROLE_LABELS[$role] ?? '---';
}

function auth_role_from_row(array $row): int
{
    return (int) ($row['funcao_original'] ?? $row['funcao'] ?? -1);
}

function auth_matrix_id_from_row(array $row): ?int
{
    $matrixId = (int) ($row['matriz_id'] ?? 0);

    return $matrixId > 0 ? $matrixId : null;
}

function auth_user_select_sql(string $alias = 'u'): string
{
    $fields = [
        "{$alias}.id",
        "{$alias}.name",
        "{$alias}.email",
        "{$alias}.password",
        "{$alias}.funcao",
        "{$alias}.funcao_original",
        "{$alias}.tb2_id",
    ];

    if (in_array('matriz_id', table_columns('users'), true)) {
        $fields[] = "{$alias}.matriz_id";
    }

    return implode(",\n            ", $fields);
}

function request_bearer_token(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '');

    if (preg_match('/Bearer\s+(.+)$/i', $header, $matches) === 1) {
        return trim((string) ($matches[1] ?? ''));
    }

    return '';
}

function base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function base64url_decode(string $value): string|false
{
    $padding = strlen($value) % 4;

    if ($padding !== 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return base64_decode(strtr($value, '-_', '+/'), true);
}

function app_secret(): string
{
    static $secret = null;

    if (is_string($secret)) {
        return $secret;
    }

    $candidates = [
        (string) (getenv('AUTH_SESSION_SECRET') ?: ''),
        (string) (getenv('APP_KEY') ?: ''),
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }

        if (str_starts_with($candidate, 'base64:')) {
            $decoded = base64_decode(substr($candidate, 7), true);
            if ($decoded !== false && $decoded !== '') {
                $secret = $decoded;
                return $secret;
            }
        }

        $secret = $candidate;
        return $secret;
    }

    $secret = hash(
        'sha256',
        implode('|', [
            (string) (getenv('DB_HOST') ?: ''),
            (string) (getenv('DB_DATABASE') ?: ''),
            (string) (getenv('DB_USERNAME') ?: ''),
            __DIR__,
        ]),
        true
    );

    return $secret;
}

function build_sql_placeholders(array $ids, string $prefix = 'id'): array
{
    $params = [];
    $placeholders = [];

    foreach (array_values($ids) as $index => $id) {
        $key = "{$prefix}_{$index}";
        $params[$key] = (int) $id;
        $placeholders[] = ':' . $key;
    }

    return [$placeholders, $params];
}

function build_unit_scope_condition(array $allowedUnitIds, string $column, string $prefix = 'unit'): array
{
    if ($allowedUnitIds === []) {
        return ['1 = 0', []];
    }

    [$placeholders, $params] = build_sql_placeholders($allowedUnitIds, $prefix);

    return [$column . ' in (' . implode(', ', $placeholders) . ')', $params];
}

function unit_is_allowed(int $unitId, array $allowedUnitIds): bool
{
    return $unitId > 0 && in_array($unitId, $allowedUnitIds, true);
}

function normalize_positive_int_ids(array $ids): array
{
    $normalized = array_values(array_unique(array_filter(
        array_map('intval', $ids),
        static fn (int $id): bool => $id > 0
    )));
    sort($normalized);

    return $normalized;
}

function first_existing_column(array $availableColumns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $availableColumns, true)) {
            return $candidate;
        }
    }

    return null;
}

function unit_matrix_id_column(): ?string
{
    $columns = table_columns('tb2_unidades');

    return first_existing_column($columns, [
        'matriz_id',
        'matrix_id',
        'tb2_matriz_id',
        'tb2_id_matriz',
        'id_matriz',
    ]);
}

function unit_type_column(): ?string
{
    $columns = table_columns('tb2_unidades');

    return first_existing_column($columns, [
        'tb2_tipo',
        'tipo',
        'tipo_unidade',
        'unidade_tipo',
        'unit_type',
    ]);
}

function unit_parent_relation_column(): ?string
{
    $columns = table_columns('tb2_unidades');

    return first_existing_column($columns, [
        'unidade_matriz_id',
        'id_unidade_matriz',
        'loja_matriz_id',
        'tb2_unidade_matriz_id',
        'tb2_pai_id',
        'tb2_id_pai',
        'id_pai',
        'pai_id',
        'parent_id',
        'tb2_parent_id',
        'unidade_pai_id',
        'id_unidade_pai',
    ]);
}

function fetch_matrix_ids_from_units(array $unitIds, string $matrixColumn): array
{
    $unitIds = normalize_positive_int_ids($unitIds);
    if ($unitIds === []) {
        return [];
    }

    [$placeholders, $params] = build_sql_placeholders($unitIds, 'matrix_seed_unit');
    $statement = db()->prepare(
        "select distinct {$matrixColumn} as matrix_id
         from tb2_unidades
         where tb2_id in (" . implode(', ', $placeholders) . ")
           and {$matrixColumn} is not null"
    );
    $statement->execute($params);

    return normalize_positive_int_ids(array_map(
        static fn (array $row): int => (int) ($row['matrix_id'] ?? 0),
        $statement->fetchAll()
    ));
}

function fetch_matrix_unit_ids_from_units(array $unitIds): array
{
    $unitIds = normalize_positive_int_ids($unitIds);
    if ($unitIds === []) {
        return [];
    }

    $typeColumn = unit_type_column();
    if ($typeColumn === null) {
        return [];
    }

    [$placeholders, $params] = build_sql_placeholders($unitIds, 'typed_seed_unit');
    $statement = db()->prepare(
        "select tb2_id
         from tb2_unidades
         where tb2_id in (" . implode(', ', $placeholders) . ")
           and lower(trim(coalesce({$typeColumn}, ''))) = 'matriz'"
    );
    $statement->execute($params);

    return normalize_positive_int_ids(array_map(
        static fn (array $row): int => (int) ($row['tb2_id'] ?? 0),
        $statement->fetchAll()
    ));
}

function fetch_unit_ids_from_matrix_ids(array $matrixIds, string $matrixColumn): array
{
    $matrixIds = normalize_positive_int_ids($matrixIds);
    if ($matrixIds === []) {
        return [];
    }

    [$placeholders, $params] = build_sql_placeholders($matrixIds, 'allowed_matrix');
    $statement = db()->prepare(
        "select tb2_id
         from tb2_unidades
         where {$matrixColumn} in (" . implode(', ', $placeholders) . ')'
    );
    $statement->execute($params);

    return normalize_positive_int_ids(array_map(
        static fn (array $row): int => (int) ($row['tb2_id'] ?? 0),
        $statement->fetchAll()
    ));
}

function expand_unit_ids_with_parent_units(array $unitIds): array
{
    $unitIds = normalize_positive_int_ids($unitIds);

    if ($unitIds === [] || !table_exists('tb2_unidades')) {
        return $unitIds;
    }

    $relationColumn = unit_parent_relation_column();
    if ($relationColumn === null) {
        return $unitIds;
    }

    [$seedPlaceholders, $seedParams] = build_sql_placeholders($unitIds, 'seed_unit');
    $seedStatement = db()->prepare(
        "select tb2_id, {$relationColumn} as matrix_unit_id
         from tb2_unidades
         where tb2_id in (" . implode(', ', $seedPlaceholders) . ')'
    );
    $seedStatement->execute($seedParams);

    $matrixUnitIds = [];
    foreach ($seedStatement->fetchAll() as $row) {
        $unitId = (int) ($row['tb2_id'] ?? 0);
        $matrixUnitId = (int) ($row['matrix_unit_id'] ?? 0);

        $matrixUnitIds[] = $matrixUnitId > 0 ? $matrixUnitId : $unitId;
    }

    $matrixUnitIds = normalize_positive_int_ids($matrixUnitIds);
    if ($matrixUnitIds === []) {
        return $unitIds;
    }

    [$matrixIdPlaceholders, $matrixIdParams] = build_sql_placeholders($matrixUnitIds, 'matrix_unit_id');
    [$matrixLinkPlaceholders, $matrixLinkParams] = build_sql_placeholders($matrixUnitIds, 'matrix_unit_link');
    $familyStatement = db()->prepare(
        "select tb2_id
         from tb2_unidades
         where tb2_id in (" . implode(', ', $matrixIdPlaceholders) . ")
            or {$relationColumn} in (" . implode(', ', $matrixLinkPlaceholders) . ')'
    );
    $familyStatement->execute([
        ...$matrixIdParams,
        ...$matrixLinkParams,
    ]);

    foreach ($familyStatement->fetchAll() as $row) {
        $unitIds[] = (int) ($row['tb2_id'] ?? 0);
    }

    return normalize_positive_int_ids($unitIds);
}

function expand_unit_ids_with_matrix_units(array $unitIds, ?int $matrixId = null): array
{
    $unitIds = normalize_positive_int_ids($unitIds);

    if (!table_exists('tb2_unidades')) {
        return $unitIds;
    }

    if ($unitIds === [] && (int) ($matrixId ?? 0) <= 0) {
        return $unitIds;
    }

    $matrixColumn = unit_matrix_id_column();
    if ($matrixColumn !== null) {
        $matrixIds = normalize_positive_int_ids([$matrixId ?? 0]);

        if ($matrixIds === []) {
            $matrixIds = fetch_matrix_ids_from_units($unitIds, $matrixColumn);
        }

        if ($matrixIds === []) {
            $matrixIds = fetch_matrix_unit_ids_from_units($unitIds);
        }

        if ($matrixIds !== []) {
            return normalize_positive_int_ids([
                ...$unitIds,
                ...fetch_unit_ids_from_matrix_ids($matrixIds, $matrixColumn),
            ]);
        }
    }

    return expand_unit_ids_with_parent_units($unitIds);
}

function fetch_allowed_unit_ids_for_user(int $userId, ?int $primaryUnitId = null, ?int $matrixId = null): array
{
    $unitIds = [];

    if ($primaryUnitId !== null && $primaryUnitId > 0) {
        $unitIds[] = $primaryUnitId;
    }

    if (table_exists('tb2_unidade_user')) {
        $statement = db()->prepare(
            'select tb2_id
             from tb2_unidade_user
             where user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);

        foreach ($statement->fetchAll() as $row) {
            $unitId = (int) ($row['tb2_id'] ?? 0);
            if ($unitId > 0) {
                $unitIds[] = $unitId;
            }
        }
    }

    return expand_unit_ids_with_matrix_units($unitIds, $matrixId);
}

function fetch_allowed_units_metadata(array $unitIds): array
{
    if ($unitIds === []) {
        return [];
    }

    [$placeholders, $params] = build_sql_placeholders($unitIds, 'meta_unit');
    $statement = db()->prepare(
        'select tb2_id as id, tb2_nome as name
         from tb2_unidades
         where tb2_id in (' . implode(', ', $placeholders) . ')
         order by tb2_nome asc'
    );
    $statement->execute($params);

    return array_map(static fn (array $row): array => [
        'id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['name'] ?? ''),
    ], $statement->fetchAll());
}

function issue_master_session_token(array $user, array $allowedUnitIds): string
{
    $payload = [
        'user_id' => (int) ($user['id'] ?? 0),
        'role' => auth_role_from_row($user),
        'allowed_unit_ids' => array_values(array_map('intval', $allowedUnitIds)),
        'exp' => time() + (60 * 60 * 24 * 7),
    ];

    $encodedPayload = base64url_encode((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $signature = hash_hmac('sha256', $encodedPayload, app_secret(), true);

    return $encodedPayload . '.' . base64url_encode($signature);
}

function parse_master_session_token(string $token): ?array
{
    if ($token === '' || !str_contains($token, '.')) {
        return null;
    }

    [$encodedPayload, $encodedSignature] = explode('.', $token, 2);
    $expectedSignature = base64url_encode(hash_hmac('sha256', $encodedPayload, app_secret(), true));

    if (!hash_equals($expectedSignature, $encodedSignature)) {
        return null;
    }

    $decodedPayload = base64url_decode($encodedPayload);
    if ($decodedPayload === false || $decodedPayload === '') {
        return null;
    }

    $payload = json_decode($decodedPayload, true);
    if (!is_array($payload)) {
        return null;
    }

    $expiresAt = (int) ($payload['exp'] ?? 0);
    if ($expiresAt <= time()) {
        return null;
    }

    return $payload;
}

function find_auth_user(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $conditions = user_activity_conditions('u');
    $conditions[] = 'u.id = :user_id';
    $whereSql = ' where ' . implode(' and ', $conditions);
    $userSelect = auth_user_select_sql('u');

    $statement = db()->prepare(
        "select
            {$userSelect}
         from users u
         {$whereSql}
         limit 1"
    );
    $statement->execute(['user_id' => $userId]);

    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function require_master_session(): array
{
    $token = request_bearer_token();
    if ($token === '') {
        json_response(['ok' => false, 'error' => 'Sessao nao autenticada.'], 401);
    }

    $payload = parse_master_session_token($token);
    if (!is_array($payload)) {
        json_response(['ok' => false, 'error' => 'Sessao invalida ou expirada.'], 401);
    }

    $user = find_auth_user((int) ($payload['user_id'] ?? 0));
    if (!$user) {
        json_response(['ok' => false, 'error' => 'Usuario da sessao nao encontrado.'], 401);
    }

    $role = auth_role_from_row($user);
    if ($role !== MASTER_ROLE) {
        json_response(['ok' => false, 'error' => 'Apenas usuarios MASTER podem acessar este app.'], 403);
    }

    $dbAllowedUnitIds = fetch_allowed_unit_ids_for_user(
        (int) ($user['id'] ?? 0),
        isset($user['tb2_id']) ? (int) $user['tb2_id'] : null,
        auth_matrix_id_from_row($user)
    );
    $allowedUnitIds = $dbAllowedUnitIds;

    if ($allowedUnitIds === []) {
        json_response(['ok' => false, 'error' => 'Nenhuma loja vinculada a este MASTER foi encontrada.'], 403);
    }

    return [
        'id' => (int) ($user['id'] ?? 0),
        'name' => (string) ($user['name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'role' => $role,
        'role_label' => auth_role_label($role),
        'unit_id' => isset($user['tb2_id']) ? (int) $user['tb2_id'] : null,
        'allowed_unit_ids' => $allowedUnitIds,
        'allowed_units' => fetch_allowed_units_metadata($allowedUnitIds),
    ];
}

function unsupported_orders_endpoint(): void
{
    json_response([
        'ok' => false,
        'error' => 'Endpoint de pedidos ainda nao configurado nesta base. As tabelas de pedidos nao foram encontradas no banco informado.',
    ], 501);
}
