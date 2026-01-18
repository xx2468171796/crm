<?php
/**
 * OKR 进度计算辅助函数
 */

require_once __DIR__ . '/db.php';

/**
 * 重新计算目标（Objective）的进度
 */
function recalculateObjectiveProgress($objectiveId)
{
    $krs = Db::query('SELECT * FROM okr_key_results WHERE objective_id = :objective_id', ['objective_id' => $objectiveId]);

    if (count($krs) === 0) {
        Db::execute('UPDATE okr_objectives SET progress = 0 WHERE id = :id', ['id' => $objectiveId]);
        return;
    }

    $totalWeight = 0;
    $weightedProgress = 0;

    foreach ($krs as $kr) {
        $weight = floatval($kr['weight']);
        if ($weight == 0) {
            $weight = 100 / count($krs);
        }
        $totalWeight += $weight;
        $weightedProgress += floatval($kr['progress']) * $weight;
    }

    $progress = $totalWeight > 0 ? round($weightedProgress / $totalWeight, 2) : 0;

    $status = 'normal';
    if ($progress < 50) {
        $status = 'at_risk';
    } elseif ($progress == 0) {
        $status = 'delayed';
    }

    Db::execute(
        'UPDATE okr_objectives SET progress = :progress, status = :status, update_time = :update_time WHERE id = :id',
        ['progress' => $progress, 'status' => $status, 'update_time' => time(), 'id' => $objectiveId]
    );
}

/**
 * 重新计算 OKR 容器的进度
 */
function recalculateContainerProgress($containerId)
{
    $objectives = Db::query('SELECT * FROM okr_objectives WHERE container_id = :container_id', ['container_id' => $containerId]);

    if (count($objectives) === 0) {
        Db::execute('UPDATE okr_containers SET progress = 0 WHERE id = :id', ['id' => $containerId]);
        return;
    }

    $totalProgress = 0;
    foreach ($objectives as $obj) {
        $totalProgress += floatval($obj['progress']);
    }

    $progress = round($totalProgress / count($objectives), 2);

    Db::execute(
        'UPDATE okr_containers SET progress = :progress, update_time = :update_time WHERE id = :id',
        ['progress' => $progress, 'update_time' => time(), 'id' => $containerId]
    );
}

