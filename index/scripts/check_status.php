<?php
require_once __DIR__ . '/../core/db.php';

$statuses = Db::query("SELECT DISTINCT current_status, COUNT(*) as cnt FROM projects WHERE deleted_at IS NULL GROUP BY current_status");
print_r($statuses);
