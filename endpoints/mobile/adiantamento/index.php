<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/_bootstrap.php';

function normalize_amount(mixed $value): float
{
    if (is_int($value) || is_float($value)) {
        return round((float) $value, 2);
    }

    $normalized = trim((string) $value);

    if ($normalized === '') {
        return 0.0;
    }

    $normalized = str_replace('R$', '', $normalized);
    $normalized = preg_replace('/\s+/', '', $normalized) ?? '';

    if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
    } elseif (str_contains($normalized, ',')) {
        $normalized = str_replace(',', '.', $normalized);
    }

    return round((float) $normalized, 2);
}

function format_salary_limit(float $value): string
{
    return number_format($value, 2, ',', '.');
}

function normalize_user_row(array $row): array
{
    $salary = (float) ($row['salary_limit'] ?? 0);

    return [
        'id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['name'] ?? ''),
        'salary_limit' => round($salary, 2),
        'formatted_limit' => format_salary_limit($salary),
        'unit_id' => $row['unit_id'] === null ? null : (int) $row['unit_id'],
        'unit_name' => (string) ($row['unit_name'] ?? ''),
    ];
}

function fetch_salary_advance_users(array $allowedUnitIds): array
{
    $conditions = user_activity_conditions('u');
    [$unitScopeSql, $unitScopeParams] = build_unit_scope_condition($allowedUnitIds, 'u.tb2_id', 'advance_user_unit');
    $conditions[] = $unitScopeSql;
    $whereSql = $conditions ? (' where ' . implode(' and ', $conditions)) : '';

    $statement = db()->prepare(
        "select
            u.id,
            u.name,
            coalesce(u.salario, 0) as salary_limit,
            u.tb2_id as unit_id,
            unit.tb2_nome as unit_name
         from users u
         left join tb2_unidades unit on unit.tb2_id = u.tb2_id
         {$whereSql}
         order by u.name asc"
    );
    $statement->execute($unitScopeParams);

    return array_map('normalize_user_row', $statement->fetchAll());
}

function find_salary_advance_user(int $userId, array $allowedUnitIds): ?array
{
    $conditions = user_activity_conditions('u');
    $conditions[] = 'u.id = :user_id';
    [$unitScopeSql, $unitScopeParams] = build_unit_scope_condition($allowedUnitIds, 'u.tb2_id', 'advance_find_unit');
    $conditions[] = $unitScopeSql;
    $whereSql = ' where ' . implode(' and ', $conditions);

    $statement = db()->prepare(
        "select
            u.id,
            u.name,
            coalesce(u.salario, 0) as salary_limit,
            u.tb2_id as unit_id,
            unit.tb2_nome as unit_name
         from users u
         left join tb2_unidades unit on unit.tb2_id = u.tb2_id
         {$whereSql}
         limit 1"
    );
    $statement->execute([
        'user_id' => $userId,
        ...$unitScopeParams,
    ]);
    $row = $statement->fetch();

    if (!$row) {
        return null;
    }

    return normalize_user_row($row);
}

function current_month_range(): array
{
    $timezone = new DateTimeZone('America/Sao_Paulo');
    $now = new DateTimeImmutable('now', $timezone);
    $start = $now->modify('first day of this month')->setTime(0, 0, 0);
    $end = $now->modify('last day of this month')->setTime(23, 59, 59);

    return [$start, $end];
}

function parse_advance_date(string $value): ?DateTimeImmutable
{
    $normalized = trim($value);

    if ($normalized === '') {
        return null;
    }

    $timezone = new DateTimeZone('America/Sao_Paulo');

    foreach (['Y-m-d', 'd/m/Y', 'd/m/y'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $normalized, $timezone);
        if ($date instanceof DateTimeImmutable && $date->format($format) === $normalized) {
            return $date->setTime(0, 0, 0);
        }
    }

    return null;
}

function fetch_current_month_advances(int $userId, string $startDate, string $endDate): array
{
    $columns = table_columns('salary_advances');
    $hasUnitId = in_array('unit_id', $columns, true);
    $unitSelect = $hasUnitId ? 'a.unit_id,' : 'null as unit_id,';
    $unitJoin = $hasUnitId
        ? 'left join tb2_unidades unit on unit.tb2_id = a.unit_id'
        : 'left join tb2_unidades unit on 1 = 0';

    $statement = db()->prepare(
        "select
            a.id,
            a.advance_date,
            a.amount,
            a.reason,
            {$unitSelect}
            unit.tb2_nome as unit_name
         from salary_advances a
         {$unitJoin}
         where a.user_id = :user_id
           and a.advance_date between :start_date and :end_date
         order by a.advance_date desc, a.id desc"
    );
    $statement->execute([
        'user_id' => $userId,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);

    return array_map(static fn (array $row): array => [
        'id' => (int) ($row['id'] ?? 0),
        'advance_date' => isset($row['advance_date']) ? (string) $row['advance_date'] : null,
        'amount' => round((float) ($row['amount'] ?? 0), 2),
        'reason' => trim((string) ($row['reason'] ?? '')),
        'unit_id' => $row['unit_id'] === null ? null : (int) $row['unit_id'],
        'unit_name' => (string) ($row['unit_name'] ?? ''),
    ], $statement->fetchAll());
}

function find_salary_advance(int $advanceId): ?array
{
    $columns = table_columns('salary_advances');
    $hasUnitId = in_array('unit_id', $columns, true);
    $unitSelect = $hasUnitId ? 'a.unit_id,' : 'null as unit_id,';

    $statement = db()->prepare(
        "select
            a.id,
            a.user_id,
            a.advance_date,
            a.amount,
            a.reason,
            {$unitSelect}
            unit.tb2_nome as unit_name
         from salary_advances a
         left join tb2_unidades unit on " . ($hasUnitId ? 'unit.tb2_id = a.unit_id' : '1 = 0') . "
         where a.id = :id
         limit 1"
    );
    $statement->execute(['id' => $advanceId]);
    $row = $statement->fetch();

    if (!$row) {
        return null;
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'user_id' => (int) ($row['user_id'] ?? 0),
        'advance_date' => isset($row['advance_date']) ? (string) $row['advance_date'] : null,
        'amount' => round((float) ($row['amount'] ?? 0), 2),
        'reason' => trim((string) ($row['reason'] ?? '')),
        'unit_id' => $row['unit_id'] === null ? null : (int) $row['unit_id'],
        'unit_name' => (string) ($row['unit_name'] ?? ''),
    ];
}

function build_salary_advance_payload(?array $selectedUser): array
{
    [$monthStart, $monthEnd] = current_month_range();
    $advances = [];
    $currentMonthTotal = 0.0;
    $activeUnit = null;

    if ($selectedUser) {
        $advances = fetch_current_month_advances(
            (int) $selectedUser['id'],
            $monthStart->format('Y-m-d'),
            $monthEnd->format('Y-m-d')
        );
        $currentMonthTotal = round(
            array_reduce(
                $advances,
                static fn (float $carry, array $item): float => $carry + (float) ($item['amount'] ?? 0),
                0.0
            ),
            2
        );

        $activeUnit = [
            'id' => $selectedUser['unit_id'],
            'name' => (string) ($selectedUser['unit_name'] ?? ''),
        ];
    }

    return [
        'selected_user' => $selectedUser,
        'active_unit' => $activeUnit,
        'current_month_advances' => $advances,
        'current_month_total' => $currentMonthTotal,
        'current_month_reference' => $monthStart->format('m/Y'),
        'current_month_start' => $monthStart->format('Y-m-d'),
        'current_month_end' => $monthEnd->format('Y-m-d'),
    ];
}

function ensure_salary_advances_table(): void
{
    if (!table_exists('salary_advances')) {
        json_response(['ok' => false, 'error' => 'Tabela salary_advances nao encontrada nesta base.'], 500);
    }
}

try {
    $auth = require_master_session();
    $allowedUnitIds = $auth['allowed_unit_ids'];

    ensure_salary_advances_table();

    if ($requestMethod === 'GET') {
        $selectedUserId = (int) ($_GET['user_id'] ?? 0);
        $selectedUser = null;

        if ($selectedUserId > 0) {
            $selectedUser = find_salary_advance_user($selectedUserId, $allowedUnitIds);

            if (!$selectedUser) {
                json_response(['ok' => false, 'error' => 'Usuario nao encontrado ou inativo.'], 404);
            }
        }

        json_response([
            'ok' => true,
            'users' => fetch_salary_advance_users($allowedUnitIds),
            ...build_salary_advance_payload($selectedUser),
        ]);
    }

    if ($requestMethod !== 'POST') {
        json_response(['ok' => false, 'error' => 'Metodo nao permitido.'], 405);
    }

    $payload = input_json();
    $action = strtolower(trim((string) ($payload['action'] ?? 'create')));

    if ($action === 'delete') {
        $advanceId = (int) ($payload['id'] ?? 0);

        if ($advanceId <= 0) {
            json_response(['ok' => false, 'error' => 'Adiantamento invalido para excluir.'], 422);
        }

        $advance = find_salary_advance($advanceId);
        if (!$advance) {
            json_response(['ok' => false, 'error' => 'Adiantamento nao encontrado.'], 404);
        }

        $selectedUser = find_salary_advance_user((int) ($advance['user_id'] ?? 0), $allowedUnitIds);
        if (!$selectedUser) {
            json_response(['ok' => false, 'error' => 'Adiantamento fora do escopo da matriz deste MASTER.'], 403);
        }

        $deleteStatement = db()->prepare('delete from salary_advances where id = :id');
        $deleteStatement->execute(['id' => $advanceId]);

        json_response([
            'ok' => true,
            'message' => 'Adiantamento excluido com sucesso!',
            'users' => fetch_salary_advance_users($allowedUnitIds),
            ...build_salary_advance_payload($selectedUser),
        ]);
    }

    $userId = (int) ($payload['user_id'] ?? 0);
    $amount = normalize_amount($payload['amount'] ?? 0);
    $reason = trim((string) ($payload['reason'] ?? ''));
    $advanceDate = parse_advance_date((string) ($payload['advance_date'] ?? ''));

    if ($userId <= 0) {
        json_response(['ok' => false, 'error' => 'Selecione um usuario valido.'], 422);
    }

    if ($amount < 0.01) {
        json_response(['ok' => false, 'error' => 'Informe um valor maior que zero.'], 422);
    }

    if (!$advanceDate) {
        json_response(['ok' => false, 'error' => 'Informe a data do adiantamento.'], 422);
    }

    $selectedUser = find_salary_advance_user($userId, $allowedUnitIds);
    if (!$selectedUser) {
        json_response(['ok' => false, 'error' => 'Usuario nao encontrado ou inativo.'], 404);
    }

    $unitId = (int) ($selectedUser['unit_id'] ?? 0);
    if ($unitId <= 0) {
        json_response(['ok' => false, 'error' => 'O usuario selecionado nao possui unidade principal definida.'], 422);
    }

    [$monthStart, $monthEnd] = current_month_range();
    $currentMonthAdvances = fetch_current_month_advances(
        $userId,
        $monthStart->format('Y-m-d'),
        $monthEnd->format('Y-m-d')
    );
    $currentMonthTotal = round(
        array_reduce(
            $currentMonthAdvances,
            static fn (float $carry, array $item): float => $carry + (float) ($item['amount'] ?? 0),
            0.0
        ),
        2
    );
    $salaryLimit = (float) ($selectedUser['salary_limit'] ?? 0);
    $newTotal = round($currentMonthTotal + $amount, 2);

    if ($newTotal > $salaryLimit) {
        json_response([
            'ok' => false,
            'error' => 'O total de adiantamentos do mes corrente excede o salario deste usuario.',
        ], 422);
    }

    $columns = table_columns('salary_advances');
    $insertColumns = ['user_id', 'advance_date', 'amount', 'reason'];
    $insertValues = [':user_id', ':advance_date', ':amount', ':reason'];
    $params = [
        'user_id' => $userId,
        'advance_date' => $advanceDate->format('Y-m-d'),
        'amount' => $amount,
        'reason' => $reason !== '' ? $reason : null,
    ];

    if (in_array('unit_id', $columns, true)) {
        $insertColumns[] = 'unit_id';
        $insertValues[] = ':unit_id';
        $params['unit_id'] = $unitId;
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');

    if (in_array('created_at', $columns, true)) {
        $insertColumns[] = 'created_at';
        $insertValues[] = ':created_at';
        $params['created_at'] = $now;
    }

    if (in_array('updated_at', $columns, true)) {
        $insertColumns[] = 'updated_at';
        $insertValues[] = ':updated_at';
        $params['updated_at'] = $now;
    }

    $statement = db()->prepare(
        'insert into salary_advances (' . implode(', ', $insertColumns) . ')
         values (' . implode(', ', $insertValues) . ')'
    );
    $statement->execute($params);

    $selectedUser = find_salary_advance_user($userId, $allowedUnitIds);

    json_response([
        'ok' => true,
        'message' => 'Adiantamento registrado com sucesso!',
        'users' => fetch_salary_advance_users($allowedUnitIds),
        ...build_salary_advance_payload($selectedUser),
    ]);
} catch (Throwable $exception) {
    json_response(['ok' => false, 'error' => 'Falha ao processar o adiantamento.'], 500);
}
