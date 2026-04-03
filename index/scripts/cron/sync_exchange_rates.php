#!/usr/bin/env php
<?php
/**
 * 汇率同步定时任务
 * 建议cron配置: */10 * * * * php /path/to/sync_exchange_rates.php
 */
require_once __DIR__ . '/../../api/exchange_rate_sync.php';
