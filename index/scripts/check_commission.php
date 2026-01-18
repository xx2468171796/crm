<?php
require_once __DIR__ . '/../core/db.php';

echo "=== Checking tech_commissions table ===\n";
try {
    $r = Db::query('SELECT * FROM tech_commissions LIMIT 5');
    echo "Found " . count($r) . " records\n";
    print_r($r);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== Checking project_tech_assignments commission ===\n";
try {
    $r = Db::query('SELECT id, project_id, tech_user_id, commission, commission_note FROM project_tech_assignments WHERE commission IS NOT NULL LIMIT 10');
    echo "Found " . count($r) . " records with commission\n";
    print_r($r);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
