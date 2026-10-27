<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** 고객 수천 명의 단일 컬럼을 CASE 문 한 번으로 갱신한다(행마다 UPDATE를 보내지 않는다). */
class BulkContactUpdater
{
    private const COLUMNS = ['churn_score', 'cluster_id'];

    private const CHUNK_SIZE = 500;

    /** @param  array<int, int|float|null>  $valuesById  고객 id => 값 */
    public function update(int $workspaceId, string $column, array $valuesById): void
    {
        if (! in_array($column, self::COLUMNS, true)) {
            throw new InvalidArgumentException("갱신할 수 없는 컬럼입니다: {$column}");
        }

        DB::transaction(function () use ($workspaceId, $column, $valuesById) {
            foreach (array_chunk($valuesById, self::CHUNK_SIZE, preserve_keys: true) as $chunk) {
                $cases = str_repeat('WHEN ? THEN ? ', count($chunk));
                $bindings = [];

                foreach ($chunk as $id => $value) {
                    array_push($bindings, $id, $value);
                }

                $ids = array_keys($chunk);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                DB::update(
                    "UPDATE contacts SET {$column} = CASE id {$cases}END WHERE workspace_id = ? AND id IN ({$placeholders})",
                    [...$bindings, $workspaceId, ...$ids],
                );
            }
        });
    }
}
