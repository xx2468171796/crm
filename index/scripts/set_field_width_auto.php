<?php
/**
 * 将字段宽度批量更新为 auto
 * 使用方式：php project/scripts/set_field_width_auto.php
 */

require_once __DIR__ . '/../core/db.php';

if (php_sapi_name() !== 'cli') {
    echo "请在命令行中运行该脚本。\n";
    exit(1);
}

try {
    $affected = Db::execute(
        "UPDATE fields 
         SET width = 'auto' 
         WHERE width IS NULL OR width = '' OR width <> 'auto'"
    );
    
    echo sprintf("已将 %d 个字段宽度设置为 auto。\n", $affected);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "更新失败: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

