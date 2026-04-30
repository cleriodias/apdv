<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/_bootstrap.php';

function fetch_fiscal_units(string $date, array $allowedUnitIds): array
{
    [$unitScopeSql, $unitScopeParams] = build_unit_scope_condition($allowedUnitIds, 'u.tb2_id', 'fiscal_unit');
    $sql = "select
            u.tb2_id as unit_id,
            u.tb2_nome as unit_name,
            coalesce(sum(pg.valor_total), 0) as total_gerado,
            coalesce(cfg.tb26_geracao_automatica_ativa, 1) as generation_active
        from tb2_unidades u
        left join tb26_configuracoes_fiscais cfg on cfg.tb2_id = u.tb2_id
        left join tb27_notas_fiscais nf on nf.tb2_id = u.tb2_id
            and nf.tb27_status = 'emitida'
            and coalesce(nf.tb27_emitida_em, nf.created_at) >= :start_at
            and coalesce(nf.tb27_emitida_em, nf.created_at) <= :end_at
        left join tb4_vendas_pg pg on pg.tb4_id = nf.tb4_id
        where u.tb2_status = 1
          and {$unitScopeSql}
        group by u.tb2_id, u.tb2_nome, cfg.tb26_geracao_automatica_ativa
        order by u.tb2_nome asc";

    $statement = db()->prepare($sql);
    $statement->execute([
        'start_at' => $date . ' 00:00:00',
        'end_at' => $date . ' 23:59:59',
        ...$unitScopeParams,
    ]);

    return array_map(static fn (array $row): array => [
        'unit_id' => (int) ($row['unit_id'] ?? 0),
        'unit_name' => (string) ($row['unit_name'] ?? ''),
        'total_gerado' => round((float) ($row['total_gerado'] ?? 0), 2),
        'generation_active' => (bool) ((int) ($row['generation_active'] ?? 1)),
    ], $statement->fetchAll());
}

function find_fiscal_unit(int $unitId, string $date, array $allowedUnitIds): ?array
{
    foreach (fetch_fiscal_units($date, $allowedUnitIds) as $unit) {
        if ((int) $unit['unit_id'] === $unitId) {
            return $unit;
        }
    }

    return null;
}

try {
    $auth = require_master_session();
    $allowedUnitIds = $auth['allowed_unit_ids'];
    $today = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');

    if ($requestMethod === 'GET') {
        $items = fetch_fiscal_units($today, $allowedUnitIds);
        $totalGenerated = array_reduce(
            $items,
            static fn (float $carry, array $item): float => $carry + (float) $item['total_gerado'],
            0.0
        );

        json_response([
            'ok' => true,
            'date' => $today,
            'items' => $items,
            'totals' => [
                'total_gerado' => round($totalGenerated, 2),
                'active_count' => count(array_filter($items, static fn (array $item): bool => (bool) $item['generation_active'])),
                'inactive_count' => count(array_filter($items, static fn (array $item): bool => ! (bool) $item['generation_active'])),
            ],
        ]);
    }

    if ($requestMethod !== 'POST') {
        json_response(['ok' => false, 'error' => 'Metodo nao permitido.'], 405);
    }

    $payload = input_json();
    $unitId = (int) ($payload['unit_id'] ?? 0);

    if ($unitId <= 0) {
        json_response(['ok' => false, 'error' => 'Loja invalida.'], 422);
    }

    if (!unit_is_allowed($unitId, $allowedUnitIds)) {
        json_response(['ok' => false, 'error' => 'Loja fora do escopo da matriz deste MASTER.'], 403);
    }

    $unitStatement = db()->prepare('select tb2_id from tb2_unidades where tb2_id = :unit_id and tb2_status = 1 limit 1');
    $unitStatement->execute(['unit_id' => $unitId]);

    if (!$unitStatement->fetch()) {
        json_response(['ok' => false, 'error' => 'Loja nao encontrada ou inativa.'], 404);
    }

    $generationActive = array_key_exists('generation_active', $payload)
        ? filter_var($payload['generation_active'], FILTER_VALIDATE_BOOLEAN)
        : null;

    if ($generationActive === null) {
        $currentStatement = db()->prepare(
            'select coalesce(tb26_geracao_automatica_ativa, 1) as generation_active
             from tb26_configuracoes_fiscais
             where tb2_id = :unit_id
             limit 1'
        );
        $currentStatement->execute(['unit_id' => $unitId]);
        $current = $currentStatement->fetch();
        $generationActive = ! (bool) ((int) ($current['generation_active'] ?? 1));
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');

    $upsert = db()->prepare(
        'insert into tb26_configuracoes_fiscais (tb2_id, tb26_geracao_automatica_ativa, created_at, updated_at)
         values (:unit_id, :generation_active, :created_at, :updated_at)
         on duplicate key update
             tb26_geracao_automatica_ativa = values(tb26_geracao_automatica_ativa),
             updated_at = values(updated_at)'
    );

    $upsert->execute([
        'unit_id' => $unitId,
        'generation_active' => $generationActive ? 1 : 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $unit = find_fiscal_unit($unitId, $today, $allowedUnitIds);

    json_response([
        'ok' => true,
        'unit' => $unit,
        'message' => $generationActive
            ? 'Geracao automatica de notas ativada.'
            : 'Geracao automatica de notas desativada.',
    ]);
} catch (Throwable $exception) {
    json_response(['ok' => false, 'error' => 'Falha ao processar nota fiscal.'], 500);
}
