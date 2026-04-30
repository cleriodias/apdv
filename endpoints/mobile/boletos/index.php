<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/_bootstrap.php';

try {
    $auth = require_master_session();
    $allowedUnitIds = $auth['allowed_unit_ids'];
    [$unitScopeSql, $unitScopeParams] = build_unit_scope_condition($allowedUnitIds, 'b.unit_id', 'boleto_unit');

    if ($requestMethod === 'POST') {
        $payload = input_json();
        $boletoId = (int) ($payload['id'] ?? 0);
        $hasIsPaid = array_key_exists('is_paid', $payload);
        $isPaid = $hasIsPaid ? (bool) $payload['is_paid'] : false;

        if ($boletoId <= 0 || !$hasIsPaid) {
            json_response(['ok' => false, 'error' => 'Boleto invalido para atualizar.'], 422);
        }

        $update = db()->prepare(
            'update tb_16_boletos
             set is_paid = :is_paid
             where id = :id
               and ' . $unitScopeSql
        );
        $update->execute([
            'id' => $boletoId,
            'is_paid' => $isPaid ? 1 : 0,
            ...$unitScopeParams,
        ]);

        $statement = db()->prepare(
            "select
                b.id,
                b.description,
                b.amount,
                b.due_date,
                b.digitable_line,
                b.is_paid,
                b.unit_id,
                u.tb2_nome as unit_name
             from tb_16_boletos b
             left join tb2_unidades u on u.tb2_id = b.unit_id
             where b.id = :id
               and {$unitScopeSql}
             limit 1"
        );
        $statement->execute([
            'id' => $boletoId,
            ...$unitScopeParams,
        ]);
        $item = $statement->fetch();

        if (!$item) {
            json_response(['ok' => false, 'error' => 'Boleto nao encontrado.'], 404);
        }

        json_response([
            'ok' => true,
            'item' => [
                'id' => (int) $item['id'],
                'description' => (string) $item['description'],
                'amount' => (float) $item['amount'],
                'due_date' => $item['due_date'],
                'digitable_line' => (string) $item['digitable_line'],
                'is_paid' => (bool) $item['is_paid'],
                'unit_id' => $item['unit_id'] === null ? null : (int) $item['unit_id'],
                'unit_name' => (string) ($item['unit_name'] ?? ''),
            ],
        ]);
    }

    $paidFilter = $_GET['paid'] ?? 'unpaid';

    $filters = [
        'start_date' => isset($_GET['start_date']) ? trim((string) $_GET['start_date']) : '',
        'end_date' => isset($_GET['end_date']) ? trim((string) $_GET['end_date']) : '',
        'paid' => in_array($paidFilter, ['all', 'paid', 'unpaid'], true) ? (string) $paidFilter : 'unpaid',
        'unit_id' => isset($_GET['unit_id']) && $_GET['unit_id'] !== '' ? (string) $_GET['unit_id'] : 'all',
    ];

    $where = [];
    $params = [];

    if ($filters['start_date'] !== '') {
        $where[] = 'b.due_date >= :start_date';
        $params['start_date'] = $filters['start_date'];
    }

    if ($filters['end_date'] !== '') {
        $where[] = 'b.due_date <= :end_date';
        $params['end_date'] = $filters['end_date'];
    }

    if ($filters['paid'] === 'paid') {
        $where[] = 'b.is_paid = 1';
    } elseif ($filters['paid'] === 'unpaid') {
        $where[] = 'b.is_paid = 0';
    }

    if ($filters['unit_id'] !== 'all') {
        $where[] = 'b.unit_id = :unit_id';
        $params['unit_id'] = (int) $filters['unit_id'];
    }

    $where[] = $unitScopeSql;
    $whereSql = ' where ' . implode(' and ', $where);
    $params = [
        ...$params,
        ...$unitScopeParams,
    ];

    $sql = "select
            b.id,
            b.description,
            b.amount,
            b.due_date,
            b.digitable_line,
            b.is_paid,
            b.unit_id,
            u.tb2_nome as unit_name
        from tb_16_boletos b
        left join tb2_unidades u on u.tb2_id = b.unit_id
        {$whereSql}
        order by b.due_date asc, b.id desc";

    $statement = db()->prepare($sql);
    $statement->execute($params);
    $items = $statement->fetchAll();

    $totalStatement = db()->prepare("select coalesce(sum(b.amount), 0) as total from tb_16_boletos b {$whereSql}");
    $totalStatement->execute($params);
    $total = (float) ($totalStatement->fetch()['total'] ?? 0);

    [$unitListSql, $unitListParams] = build_unit_scope_condition($allowedUnitIds, 'tb2_id', 'filter_unit');
    $unitsStatement = db()->prepare(
        "select tb2_id as id, tb2_nome as name
         from tb2_unidades
         where tb2_status = 1
           and {$unitListSql}
         order by tb2_nome"
    );
    $unitsStatement->execute($unitListParams);
    $units = $unitsStatement->fetchAll();

    json_response([
        'ok' => true,
        'items' => array_map(static function (array $item): array {
            return [
                'id' => (int) $item['id'],
                'description' => (string) $item['description'],
                'amount' => (float) $item['amount'],
                'due_date' => $item['due_date'],
                'digitable_line' => (string) $item['digitable_line'],
                'is_paid' => (bool) $item['is_paid'],
                'unit_id' => $item['unit_id'] === null ? null : (int) $item['unit_id'],
                'unit_name' => (string) ($item['unit_name'] ?? ''),
            ];
        }, $items),
        'filter_units' => array_map(static fn (array $unit): array => [
            'id' => (int) $unit['id'],
            'name' => (string) $unit['name'],
        ], $units),
        'filters' => $filters,
        'list_total_amount' => $total,
    ]);
} catch (Throwable $exception) {
    json_response(['ok' => false, 'error' => 'Falha ao carregar boletos.'], 500);
}
