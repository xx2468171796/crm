<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/finance_dashboard_service.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = Auth::check();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    $viewMode = $input['viewMode'] ?? 'contract';
    $filters = $input['filters'] ?? [];
    $groupBy = $input['groupBy'] ?? [];
    $sortBy = $input['sortBy'] ?? '';
    $sortDir = $input['sortDir'] ?? 'asc';
    $page = max(1, (int)($input['page'] ?? 1));
    $perPage = min(100, max(10, (int)($input['perPage'] ?? 20)));

    if (!in_array($viewMode, ['contract', 'installment', 'staff_summary'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid view mode']);
        exit;
    }

    $service = new FinanceDashboardService($user);
    
    $startTime = microtime(true);
    
    $result = $service->getData([
        'viewMode' => $viewMode,
        'filters' => $filters,
        'groupBy' => $groupBy,
        'sortBy' => $sortBy,
        'sortDir' => $sortDir,
        'page' => $page,
        'perPage' => $perPage
    ]);
    
    $queryTime = round((microtime(true) - $startTime) * 1000, 2);
    error_log("FINANCE_DASHBOARD_API: viewMode={$viewMode}, page={$page}, queryTime={$queryTime}ms");

    echo json_encode([
        'success' => true,
        'data' => $result,
        'meta' => [
            'queryTime' => $queryTime
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("FINANCE_DASHBOARD_API_ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
