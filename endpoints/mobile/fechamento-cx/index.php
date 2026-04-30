<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/_bootstrap.php';

function parse_report_date(?string $value): string
{
    $timezone = new DateTimeZone('America/Sao_Paulo');

    if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);

        if ($date instanceof DateTimeImmutable) {
            return $date->format('Y-m-d');
        }
    }

    return (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
}

function current_report_date(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
}

function fetch_cash_closures(string $date, array $allowedUnitIds): array
{
    [$unitScopeSql, $unitScopeParams] = build_unit_scope_condition($allowedUnitIds, 'c.unit_id', 'closure_unit');
    $sql = "select
            c.id,
            c.user_id as cashier_id,
            u.name as cashier_name,
            c.unit_id,
            coalesce(c.unit_name, un.tb2_nome) as unit_name,
            c.cash_amount,
            c.card_amount,
            c.closed_date,
            c.closed_at
        from cashier_closures c
        left join users u on u.id = c.user_id
        left join tb2_unidades un on un.tb2_id = c.unit_id
        where c.closed_date = :closed_date
          and {$unitScopeSql}
        order by c.closed_at desc, u.name asc, unit_name asc";

    $statement = db()->prepare($sql);
    $statement->execute([
        'closed_date' => $date,
        ...$unitScopeParams,
    ]);

    return $statement->fetchAll();
}

function fetch_registered_totals(string $date, array $allowedUnitIds): array
{
    [$unitScopeSql, $unitScopeParams] = build_unit_scope_condition($allowedUnitIds, 'sales.id_unidade', 'registered_unit');
    $sql = "select
            sales.id_user_caixa as cashier_id,
            sales.id_unidade as unit_id,
            u.name as cashier_name,
            un.tb2_nome as unit_name,
            sum(
                case
                    when payments.tipo_pagamento in ('dinheiro', 'dinheiro_cartao_credito', 'dinheiro_cartao_debito')
                        then greatest(payments.valor_total - greatest(coalesce(payments.dois_pgto, 0), 0), 0)
                    else 0
                end
            ) as cash_registered,
            sum(
                case
                    when payments.tipo_pagamento in ('cartao_credito', 'cartao_debito', 'maquina')
                        then greatest(payments.valor_total, 0)
                    when payments.tipo_pagamento in ('dinheiro', 'dinheiro_cartao_credito', 'dinheiro_cartao_debito')
                        then greatest(coalesce(payments.dois_pgto, 0), 0)
                    else 0
                end
            ) as card_registered
        from tb4_vendas_pg payments
        inner join (
            select
                tb4_id,
                min(id_user_caixa) as id_user_caixa,
                min(id_unidade) as id_unidade
            from tb3_vendas
            where tb4_id is not null
              and id_user_caixa is not null
            group by tb4_id
        ) sales on sales.tb4_id = payments.tb4_id
        left join users u on u.id = sales.id_user_caixa
        left join tb2_unidades un on un.tb2_id = sales.id_unidade
        where payments.created_at >= :start_at
          and payments.created_at <= :end_at
          and {$unitScopeSql}
        group by sales.id_user_caixa, sales.id_unidade, u.name, un.tb2_nome";

    $statement = db()->prepare($sql);
    $statement->execute([
        'start_at' => $date . ' 00:00:00',
        'end_at' => $date . ' 23:59:59',
        ...$unitScopeParams,
    ]);

    $totals = [];

    foreach ($statement->fetchAll() as $row) {
        $cashierId = (int) ($row['cashier_id'] ?? 0);
        $unitId = (int) ($row['unit_id'] ?? 0);
        $key = $cashierId . '-' . $unitId;
        $totals[$key] = [
            'cashier_id' => $cashierId,
            'cashier_name' => trim((string) ($row['cashier_name'] ?? '')) ?: ('Caixa #' . $cashierId),
            'unit_id' => $unitId > 0 ? $unitId : null,
            'unit_name' => trim((string) ($row['unit_name'] ?? '')) ?: ($unitId > 0 ? 'Unidade #' . $unitId : '---'),
            'cash_registered' => round((float) ($row['cash_registered'] ?? 0), 2),
            'card_registered' => round((float) ($row['card_registered'] ?? 0), 2),
        ];
    }

    return $totals;
}

function fetch_expense_totals(string $date, array $allowedUnitIds): array
{
    $totals = [];

    try {
        [$unitScopeSql, $unitScopeParams] = build_unit_scope_condition($allowedUnitIds, 'unit_id', 'expense_unit');
        $sql = "select
                user_id as cashier_id,
                unit_id,
                sum(amount) as expense_total
            from expenses
            where expense_date = :expense_date
              and user_id is not null
              and {$unitScopeSql}
            group by user_id, unit_id";

        $statement = db()->prepare($sql);
        $statement->execute([
            'expense_date' => $date,
            ...$unitScopeParams,
        ]);

        foreach ($statement->fetchAll() as $row) {
            $key = (int) ($row['cashier_id'] ?? 0) . '-' . (int) ($row['unit_id'] ?? 0);
            $totals[$key] = round((float) ($row['expense_total'] ?? 0), 2);
        }
    } catch (Throwable $exception) {
        return [];
    }

    return $totals;
}

try {
    $auth = require_master_session();
    $allowedUnitIds = $auth['allowed_unit_ids'];

    if ($requestMethod !== 'GET') {
        json_response(['ok' => false, 'error' => 'Metodo nao permitido.'], 405);
    }

    $date = parse_report_date($_GET['date'] ?? null);
    $isCurrentDate = $date === current_report_date();
    $closures = fetch_cash_closures($date, $allowedUnitIds);
    $registeredTotals = fetch_registered_totals($date, $allowedUnitIds);
    $expenseTotals = fetch_expense_totals($date, $allowedUnitIds);
    $closuresByKey = [];

    foreach ($closures as $closure) {
        $closureKey = (int) ($closure['cashier_id'] ?? 0) . '-' . (int) ($closure['unit_id'] ?? 0);
        $closuresByKey[$closureKey] = $closure;
    }

    $recordKeys = $isCurrentDate
        ? array_values(array_unique(array_merge(array_keys($registeredTotals), array_keys($closuresByKey))))
        : array_keys($closuresByKey);

    $items = [];
    $summary = [
        'registered_total' => 0.0,
        'informed_total' => 0.0,
        'difference_total' => 0.0,
    ];

    foreach ($recordKeys as $key) {
        $closure = $closuresByKey[$key] ?? null;
        $registered = $registeredTotals[$key] ?? null;
        $closureCashierId = (int) ($closure['cashier_id'] ?? 0);
        $closureUnitId = (int) ($closure['unit_id'] ?? 0);
        $cashierId = (int) ($registered['cashier_id'] ?? $closureCashierId);
        $unitId = (int) ($registered['unit_id'] ?? ($closureUnitId > 0 ? $closureUnitId : 0));

        $registeredValues = $registered ?? [
            'cash_registered' => 0.0,
            'card_registered' => 0.0,
            'cashier_id' => $cashierId,
            'cashier_name' => trim((string) ($closure['cashier_name'] ?? '')) ?: ('Caixa #' . $cashierId),
            'unit_id' => $unitId > 0 ? $unitId : null,
            'unit_name' => trim((string) ($closure['unit_name'] ?? '')) ?: ($unitId > 0 ? 'Unidade #' . $unitId : '---'),
        ];

        $expenseTotal = (float) ($expenseTotals[$key] ?? 0.0);
        $cashRegistered = max((float) $registeredValues['cash_registered'] - $expenseTotal, 0.0);
        $cardRegistered = (float) $registeredValues['card_registered'];
        $totalRegistered = round($cashRegistered + $cardRegistered, 2);

        $cashInformed = round((float) ($closure['cash_amount'] ?? 0), 2);
        $cardInformed = round((float) ($closure['card_amount'] ?? 0), 2);
        $totalInformed = round($cashInformed + $cardInformed, 2);
        $difference = round($totalRegistered - $totalInformed, 2);

        $summary['registered_total'] += $totalRegistered;
        $summary['informed_total'] += $totalInformed;
        $summary['difference_total'] += $difference;

        $items[] = [
            'id' => (int) ($closure['id'] ?? 0),
            'cashier_id' => $cashierId,
            'cashier_name' => trim((string) ($registeredValues['cashier_name'] ?? $closure['cashier_name'] ?? '')) ?: ('Caixa #' . $cashierId),
            'unit_id' => $unitId > 0 ? $unitId : null,
            'unit_name' => trim((string) ($registeredValues['unit_name'] ?? $closure['unit_name'] ?? '')) ?: ($unitId > 0 ? 'Unidade #' . $unitId : '---'),
            'closed_date' => (string) ($closure['closed_date'] ?? $date),
            'closed_at' => isset($closure['closed_at']) ? (string) $closure['closed_at'] : null,
            'is_live' => $isCurrentDate,
            'has_closure' => $closure !== null,
            'cash_registered' => round($cashRegistered, 2),
            'card_registered' => round($cardRegistered, 2),
            'total_registered' => $totalRegistered,
            'expense_total' => round($expenseTotal, 2),
            'cash_informed' => $cashInformed,
            'card_informed' => $cardInformed,
            'total_informed' => $totalInformed,
            'difference' => $difference,
        ];
    }

    usort($items, static function (array $left, array $right): int {
        $leftDiff = abs((float) ($left['difference'] ?? 0));
        $rightDiff = abs((float) ($right['difference'] ?? 0));

        if ($leftDiff === $rightDiff) {
            return strcmp(
                (string) ($left['cashier_name'] ?? ''),
                (string) ($right['cashier_name'] ?? '')
            );
        }

        return $rightDiff <=> $leftDiff;
    });

    json_response([
        'ok' => true,
        'date' => $date,
        'items' => $items,
        'totals' => [
            'registered_total' => round($summary['registered_total'], 2),
            'informed_total' => round($summary['informed_total'], 2),
            'difference_total' => round($summary['difference_total'], 2),
            'count' => count($items),
        ],
    ]);
} catch (Throwable $exception) {
    json_response(['ok' => false, 'error' => 'Falha ao carregar o fechamento de caixa.'], 500);
}
