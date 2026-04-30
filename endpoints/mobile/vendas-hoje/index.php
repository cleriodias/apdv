<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/_bootstrap.php';

function normalize_payment_bucket(?string $paymentType): ?string
{
    return match ((string) $paymentType) {
        'cartao_credito', 'cartao_debito', 'maquina' => 'cartao',
        'dinheiro', 'dinheiro_cartao_credito', 'dinheiro_cartao_debito' => 'dinheiro',
        default => null,
    };
}

try {
    $auth = require_master_session();
    $allowedUnitIds = $auth['allowed_unit_ids'];
    [$unitScopeSql, $unitScopeParams] = build_unit_scope_condition($allowedUnitIds, 'v.id_unidade', 'sales_unit');

    $period = strtolower((string) ($_GET['period'] ?? 'today'));
    $baseDate = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));

    if ($period === 'yesterday') {
        $baseDate = $baseDate->modify('-1 day');
    } else {
        $period = 'today';
    }

    $date = $baseDate->format('Y-m-d');

    $sql = "select
            p.tb4_id,
            p.valor_total,
            p.tipo_pagamento,
            p.dois_pgto,
            v.id_unidade as unit_id,
            u.tb2_nome as unit_name
        from tb4_vendas_pg p
        inner join (
            select tb4_id, min(id_unidade) as id_unidade
            from tb3_vendas
            where tb4_id is not null
            group by tb4_id
        ) v on v.tb4_id = p.tb4_id
        left join tb2_unidades u on u.tb2_id = v.id_unidade
        where p.created_at >= :start_at
          and p.created_at <= :end_at
          and {$unitScopeSql}
        order by u.tb2_nome asc, p.tb4_id asc";

    $statement = db()->prepare($sql);
    $statement->execute([
        'start_at' => $date . ' 00:00:00',
        'end_at' => $date . ' 23:59:59',
        ...$unitScopeParams,
    ]);

    $stores = [];
    $totals = [
        'dinheiro' => 0.0,
        'cartao' => 0.0,
        'total' => 0.0,
    ];

    foreach ($statement->fetchAll() as $row) {
        $unitId = (int) ($row['unit_id'] ?? 0);
        $unitName = trim((string) ($row['unit_name'] ?? ''));
        $key = (string) $unitId;

        if (!isset($stores[$key])) {
            $stores[$key] = [
                'unit_id' => $unitId,
                'unit_name' => $unitName !== '' ? $unitName : 'Loja nao informada',
                'dinheiro' => 0.0,
                'cartao' => 0.0,
                'total' => 0.0,
            ];
        }

        $bucket = normalize_payment_bucket($row['tipo_pagamento'] ?? null);
        if ($bucket === null) {
            continue;
        }

        $amount = max((float) ($row['valor_total'] ?? 0), 0);
        $cardPart = max((float) ($row['dois_pgto'] ?? 0), 0);

        if ($bucket === 'dinheiro') {
            $cashPart = max($amount - $cardPart, 0);

            if ($cashPart > 0) {
                $stores[$key]['dinheiro'] += $cashPart;
                $stores[$key]['total'] += $cashPart;
                $totals['dinheiro'] += $cashPart;
                $totals['total'] += $cashPart;
            }

            if ($cardPart > 0) {
                $stores[$key]['cartao'] += $cardPart;
                $stores[$key]['total'] += $cardPart;
                $totals['cartao'] += $cardPart;
                $totals['total'] += $cardPart;
            }

            continue;
        }

        $stores[$key]['cartao'] += $amount;
        $stores[$key]['total'] += $amount;
        $totals['cartao'] += $amount;
        $totals['total'] += $amount;
    }

    $items = array_values(array_map(static fn (array $store): array => [
        'unit_id' => $store['unit_id'],
        'unit_name' => $store['unit_name'],
        'dinheiro' => round($store['dinheiro'], 2),
        'cartao' => round($store['cartao'], 2),
        'total' => round($store['total'], 2),
    ], $stores));

    usort($items, static fn (array $left, array $right): int => strcmp($left['unit_name'], $right['unit_name']));

    json_response([
        'ok' => true,
        'date' => $date,
        'period' => $period,
        'items' => $items,
        'totals' => [
            'dinheiro' => round($totals['dinheiro'], 2),
            'cartao' => round($totals['cartao'], 2),
            'total' => round($totals['total'], 2),
        ],
    ]);
} catch (Throwable $exception) {
    json_response(['ok' => false, 'error' => 'Falha ao carregar vendas de hoje.'], 500);
}
