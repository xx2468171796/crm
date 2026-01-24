<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 项目详情 API
 * 
 * GET ?id=123 - 获取项目详情
 * GET ?id=123&tab=forms - 获取动态表单
 * GET ?id=123&tab=files - 获取交付物
 * GET ?id=123&tab=messages - 获取沟通记录
 */

// 抑制 PHP 警告输出，避免破坏 JSON 响应
error_reporting(E_ERROR | E_PARSE);

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/services/ProjectService.php';
require_once __DIR__ . '/../services/MultipartUploadService.php';
require_once __DIR__ . '/../services/S3Service.php';

// 认证
$user = desktop_auth_require();

// 判断是否为管理员
$isManager = in_array($user['role'] ?? '', ['super_admin', 'admin', 'manager', 'tech_manager', 'design_manager', 'dept_leader']);

$projectId = (int)($_GET['id'] ?? 0);
$tab = $_GET['tab'] ?? 'overview';

if ($projectId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '项目ID无效'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 使用 ProjectService 的状态定义
$STATUS_LIST = array_map(function($key, $value) {
    return ['key' => $key, 'label' => $key, 'color' => $value['color'], 'order' => $value['order']];
}, array_keys(ProjectService::STAGES), ProjectService::STAGES);

try {
    // 获取项目基本信息
    $project = Db::queryOne("
        SELECT 
            p.id, p.project_code, p.project_name, p.current_status,
            p.create_time, p.update_time, p.start_date, p.deadline,
            p.completed_at, p.completed_by,
            c.id as customer_id, c.name as customer_name, 
            c.group_code as customer_group_code, c.customer_group as customer_group_name,
            c.alias as customer_alias, c.mobile as customer_phone,
            pl.token as portal_token, pl.password as portal_password
        FROM projects p
        LEFT JOIN customers c ON p.customer_id = c.id AND c.deleted_at IS NULL
        LEFT JOIN portal_links pl ON pl.customer_id = c.id AND pl.enabled = 1
        WHERE p.id = ? AND p.deleted_at IS NULL
    ", [$projectId]);
    
    if (!$project) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '项目不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 非管理员需要检查是否有权限访问此项目
    if (!$isManager) {
        // 检查是否是技术负责人或项目创建者
        $hasAccess = Db::queryOne("
            SELECT 1 FROM project_tech_assignments 
            WHERE project_id = ? AND tech_user_id = ?
        ", [$projectId, $user['id']]);
        
        // 也检查是否是项目创建者
        $isCreator = Db::queryOne("
            SELECT 1 FROM projects 
            WHERE id = ? AND created_by = ?
        ", [$projectId, $user['id']]);
        
        if (!$hasAccess && !$isCreator) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => '无权访问此项目'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    // 获取技术负责人
    $techUsers = Db::query("
        SELECT pta.id as assignment_id, u.id, u.username, u.realname, pta.commission_amount, pta.commission_note
        FROM project_tech_assignments pta
        LEFT JOIN users u ON pta.tech_user_id = u.id
        WHERE pta.project_id = ?
    ", [$projectId]);
    
    $techList = [];
    foreach ($techUsers as $tech) {
        $techList[] = [
            'id' => (int)$tech['id'],
            'assignment_id' => (int)$tech['assignment_id'],
            'name' => $tech['realname'] ?: $tech['username'],
            'commission' => $tech['commission_amount'] ? (float)$tech['commission_amount'] : null,
            'commission_note' => $tech['commission_note'],
        ];
    }
    
    // 根据 tab 返回不同数据
    $tabData = null;
    
    switch ($tab) {
        case 'forms':
            $tabData = getProjectForms($projectId);
            break;
        case 'files':
            $tabData = getProjectFiles($projectId);
            break;
        case 'messages':
            $tabData = getProjectMessages($projectId);
            break;
        case 'timeline':
            $tabData = getProjectTimeline($projectId);
            break;
        default:
            // overview - 不需要额外数据
            break;
    }
    
    // 计算状态进度
    $currentStatusOrder = 0;
    foreach ($STATUS_LIST as $status) {
        if ($status['key'] === $project['current_status']) {
            $currentStatusOrder = $status['order'];
            break;
        }
    }
    
    // 获取状态变更历史
    $statusHistory = Db::query("
        SELECT psl.to_status, psl.changed_at, u.realname as changed_by_name
        FROM project_status_log psl
        LEFT JOIN users u ON psl.changed_by = u.id
        WHERE psl.project_id = ?
        ORDER BY psl.changed_at ASC
    ", [$projectId]);
    
    $statusTimeMap = [];
    foreach ($statusHistory as $log) {
        $statusTimeMap[$log['to_status']] = [
            'changed_at' => $log['changed_at'] ? date('m-d H:i', $log['changed_at']) : null,
            'changed_by' => $log['changed_by_name'],
        ];
    }
    
    // 获取阶段时间数据（计划天数）
    $stageTimes = Db::query("
        SELECT stage_from, stage_to, planned_days, status
        FROM project_stage_times
        WHERE project_id = ?
        ORDER BY stage_order ASC
    ", [$projectId]);
    
    // 构建阶段天数映射（stage_to 对应的天数）
    $stageDaysMap = [];
    foreach ($stageTimes as $st) {
        $stageDaysMap[$st['stage_to']] = [
            'days' => (int)$st['planned_days'],
            'status' => $st['status'],
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'project' => [
                'id' => (int)$project['id'],
                'project_code' => $project['project_code'],
                'project_name' => $project['project_name'],
                'current_status' => $project['current_status'],
                'remark' => '',
                'start_date' => $project['start_date'],
                'deadline' => $project['deadline'],
                'days_info' => calculateStageBasedDaysInfo($projectId),
                'progress' => calculateProgress($currentStatusOrder, count($STATUS_LIST)),
                'create_time' => $project['create_time'] ? date('Y-m-d H:i', $project['create_time']) : null,
                'update_time' => $project['update_time'] ? date('Y-m-d H:i', $project['update_time']) : null,
                'completed_at' => $project['completed_at'] ? (is_numeric($project['completed_at']) ? date('Y-m-d H:i', $project['completed_at']) : $project['completed_at']) : null,
                'completed_by' => $project['completed_by'],
            ],
            'customer' => [
                'id' => (int)$project['customer_id'],
                'name' => $project['customer_name'],
                'group_code' => $project['customer_group_code'],
                'customer_group_name' => $project['customer_group_name'] ?? null,
                'alias' => $project['customer_alias'] ?? null,
                // 技术人员不显示客户联系方式
                'phone' => $isManager ? $project['customer_phone'] : null,
                // 门户信息
                'portal_token' => $project['portal_token'] ?? null,
                'portal_password' => $project['portal_password'] ?? null,
            ],
            'tech_users' => $techList,
            'statuses' => $STATUS_LIST,
            'current_status_order' => $currentStatusOrder,
            'status_time_map' => $statusTimeMap,
            'stage_days_map' => $stageDaysMap,
            'tab_data' => $tabData,
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[API] desktop_project_detail 错误: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * 基于阶段时间计算周期天数信息（与后台一致）
 */
function calculateStageBasedDaysInfo($projectId) {
    $result = [
        'total_days' => null,
        'elapsed_days' => null,
        'remaining_days' => null,
        'overall_progress' => 0,
        'is_overdue' => false,
        'overdue_days' => 0,
        'date_range' => null,
        'is_completed' => false,
        'actual_days' => null,
        'completed_at' => null,
    ];
    
    // 检查项目是否已完工
    $project = Db::queryOne("SELECT completed_at FROM projects WHERE id = ?", [$projectId]);
    $isCompleted = !empty($project['completed_at']);
    
    // 查询阶段时间数据
    $stageTimes = Db::query("
        SELECT planned_days, planned_start_date, planned_end_date, status
        FROM project_stage_times
        WHERE project_id = ?
        ORDER BY stage_order ASC
    ", [$projectId]);
    
    if (empty($stageTimes)) {
        return $result;
    }
    
    $totalDays = 0;
    $elapsedDays = 0;
    $firstStartDate = null;
    $lastEndDate = null;
    
    // 如果项目已完工，计算实际用时
    $actualDays = 0;
    if ($isCompleted && !empty($project['completed_at']) && !empty($stageTimes[0]['planned_start_date'])) {
        $completedAtTimestamp = is_numeric($project['completed_at']) ? $project['completed_at'] : strtotime($project['completed_at']);
        if ($completedAtTimestamp) {
            $startDate = new DateTime($stageTimes[0]['planned_start_date']);
            $completedDate = new DateTime(date('Y-m-d', $completedAtTimestamp));
            $actualDays = max(1, $completedDate->diff($startDate)->days + 1);
        }
    }
    
    foreach ($stageTimes as $st) {
        $plannedDays = intval($st['planned_days']);
        $totalDays += $plannedDays;
        
        // 记录日期范围
        if (!$firstStartDate && $st['planned_start_date']) {
            $firstStartDate = $st['planned_start_date'];
        }
        if ($st['planned_end_date']) {
            $lastEndDate = $st['planned_end_date'];
        }
        
        // 计算已过天数
        if ($isCompleted) {
            // 项目已完工，所有阶段视为完成
            $elapsedDays += $plannedDays;
        } elseif ($st['status'] === 'completed') {
            $elapsedDays += $plannedDays;
        } elseif ($st['status'] === 'in_progress' && !empty($st['planned_start_date'])) {
            $startDate = new DateTime($st['planned_start_date']);
            $today = new DateTime();
            $daysPassed = max(0, $today->diff($startDate)->days + 1);
            $elapsedDays += min($daysPassed, $plannedDays);
        }
    }
    
    $result['total_days'] = $totalDays;
    $result['is_completed'] = $isCompleted;
    
    if ($isCompleted) {
        // 已完工：显示实际用时，进度100%
        $result['elapsed_days'] = $actualDays;
        $result['actual_days'] = $actualDays;
        $result['remaining_days'] = 0;
        $result['overall_progress'] = 100;
        $result['is_overdue'] = false;
        $result['overdue_days'] = 0;
        if (!empty($project['completed_at'])) {
            $completedAtTs = is_numeric($project['completed_at']) ? $project['completed_at'] : strtotime($project['completed_at']);
            $result['completed_at'] = $completedAtTs ? date('Y-m-d H:i', $completedAtTs) : null;
        }
        
        // 日期范围显示实际完成日期
        if ($firstStartDate && !empty($project['completed_at'])) {
            $completedAtTs = is_numeric($project['completed_at']) ? $project['completed_at'] : strtotime($project['completed_at']);
            $result['date_range'] = $firstStartDate . ' ~ ' . ($completedAtTs ? date('Y-m-d', $completedAtTs) : $lastEndDate) . ' (已完工)';
        } elseif ($firstStartDate) {
            $result['date_range'] = $firstStartDate . ' ~ ' . ($lastEndDate ?: '未知') . ' (已完工)';
        }
    } else {
        $result['elapsed_days'] = $elapsedDays;
        $result['remaining_days'] = max(0, $totalDays - $elapsedDays);
        $result['overall_progress'] = $totalDays > 0 ? min(100, round($elapsedDays * 100 / $totalDays)) : 0;
        
        // 日期范围
        if ($firstStartDate && $lastEndDate) {
            $result['date_range'] = $firstStartDate . ' ~ ' . $lastEndDate;
        }
        
        // 检查是否超期
        if ($lastEndDate) {
            $endDate = new DateTime($lastEndDate);
            $today = new DateTime();
            if ($today > $endDate) {
                $result['is_overdue'] = true;
                $result['overdue_days'] = $today->diff($endDate)->days;
            }
        }
    }
    
    return $result;
}

/**
 * 计算项目进度百分比
 */
function calculateProgress($currentOrder, $totalStatuses) {
    if ($totalStatuses <= 1) return 0;
    return round(($currentOrder - 1) / ($totalStatuses - 1) * 100);
}

/**
 * 获取项目动态表单（使用 form_instances 表）
 */
function getProjectForms($projectId) {
    try {
        $forms = Db::query("
            SELECT fi.id, fi.instance_name, fi.fill_token, fi.status, fi.requirement_status,
                   fi.create_time, fi.update_time,
                   ft.name as template_name, ft.form_type,
                   ftv.version_number,
                   (SELECT COUNT(*) FROM form_submissions fs WHERE fs.instance_id = fi.id) as submission_count
            FROM form_instances fi
            JOIN form_templates ft ON fi.template_id = ft.id
            JOIN form_template_versions ftv ON fi.template_version_id = ftv.id
            WHERE fi.project_id = ?
            ORDER BY fi.create_time DESC
        ", [$projectId]);
        
        $result = [];
        foreach ($forms as $form) {
            $result[] = [
                'id' => (int)$form['id'],
                'instance_name' => $form['instance_name'],
                'template_name' => $form['template_name'],
                'form_type' => $form['form_type'],
                'fill_token' => $form['fill_token'],
                'status' => $form['status'],
                'requirement_status' => $form['requirement_status'] ?: 'pending',
                'submission_count' => (int)$form['submission_count'],
                'create_time' => $form['create_time'] ? date('Y-m-d H:i', $form['create_time']) : null,
                'update_time' => $form['update_time'] ? date('Y-m-d H:i', $form['update_time']) : null,
            ];
        }
        return $result;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * 获取项目交付物（按分类）
 */
function getProjectFiles($projectId) {
    try {
        $projectRow = Db::queryOne(
            "SELECT p.project_name, p.project_code, c.group_code
             FROM projects p
             LEFT JOIN customers c ON p.customer_id = c.id
             WHERE p.id = ? AND p.deleted_at IS NULL",
            [$projectId]
        );

        $config = storage_config();
        $s3Config = $config['s3'] ?? [];
        $prefix = trim((string)($s3Config['prefix'] ?? ''), '/');

        // 从 deliverables 表获取文件
        $files = Db::query("
            SELECT 
                d.id, d.deliverable_name as filename, d.file_path, d.file_size,
                d.file_category, d.approval_status, d.visibility_level,
                d.submitted_at as create_time, d.is_folder,
                d.submitted_by as uploader_id,
                u.realname as uploader_name
            FROM deliverables d
            LEFT JOIN users u ON d.submitted_by = u.id
            WHERE d.project_id = ? AND d.deleted_at IS NULL AND d.is_folder = 0
            ORDER BY d.file_category, d.submitted_at DESC
        ", [$projectId]);
        
        // 按分类组织（key 必须与桌面端前端一致）
        $categories = [
            '客户文件' => ['label' => '客户文件', 'files' => []],
            '作品文件' => ['label' => '作品文件', 'files' => []],
            '模型文件' => ['label' => '模型文件', 'files' => []],
        ];

        $uploadService = new MultipartUploadService();
        $knownStorageKeys = [];
        
        foreach ($files as $file) {
            $categoryKey = '作品文件';
            $rawCategory = $file['file_category'] ?? 'artwork_file';
            if ($rawCategory === 'customer_file') {
                $categoryKey = '客户文件';
            } elseif ($rawCategory === 'model_file') {
                $categoryKey = '模型文件';
            }

            $rawPath = (string)($file['file_path'] ?? '');
            $storageKey = '';
            $downloadUrl = '';

            if ($rawPath !== '' && filter_var($rawPath, FILTER_VALIDATE_URL)) {
                $downloadUrl = $rawPath;
            } elseif ($rawPath !== '') {
                $storageKey = $rawPath;
                if ($prefix && strpos($storageKey, $prefix . '/') === 0) {
                    $storageKey = substr($storageKey, strlen($prefix) + 1);
                }
                try {
                    $downloadUrl = $uploadService->getDownloadPresignedUrl($storageKey, 3600);
                } catch (Exception $e) {
                    $downloadUrl = '';
                }
            }

            if ($storageKey !== '') {
                $knownStorageKeys[$storageKey] = true;
            }

            // 确保filename只是文件名，不包含路径前缀
            $cleanFilename = basename(str_replace('\\', '/', $file['filename'] ?? ''));
            
            $categories[$categoryKey]['files'][] = [
                'id' => (int)$file['id'],
                'filename' => $cleanFilename,
                'file_path' => $file['file_path'],
                'storage_key' => $storageKey,
                'download_url' => $downloadUrl,
                'file_size' => (int)$file['file_size'],
                'approval_status' => $file['approval_status'],
                'uploader_id' => (int)($file['uploader_id'] ?? 0),
                'uploader_name' => $file['uploader_name'],
                'create_time' => $file['create_time'] ? date('Y-m-d H:i', $file['create_time']) : null,
            ];
        }

        // 补充：从 S3 groups/{groupCode}/{projectName}/{分类}/ 列出文件（兼容未落库的记录）
        if (!empty($projectRow) && !empty($projectRow['group_code'])) {
            $groupCode = (string)$projectRow['group_code'];
            $projectName = $projectRow['project_name'] ?: $projectRow['project_code'] ?: ('项目' . $projectId);
            $projectName = preg_replace('/[\/\\:*?"<>|]/', '_', $projectName);

            try {
                $s3 = new S3Service();

                foreach (['客户文件', '作品文件', '模型文件'] as $categoryName) {
                    $searchPrefix = "groups/{$groupCode}/{$projectName}/{$categoryName}/";
                    if ($prefix) {
                        $searchPrefix = $prefix . '/' . $searchPrefix;
                    }

                    $objects = $s3->listObjects($searchPrefix, '');
                    foreach ($objects as $obj) {
                        $objKey = (string)($obj['storage_key'] ?? '');
                        if ($objKey === '') {
                            continue;
                        }

                        $storageKeyNoPrefix = $objKey;
                        if ($prefix && strpos($storageKeyNoPrefix, $prefix . '/') === 0) {
                            $storageKeyNoPrefix = substr($storageKeyNoPrefix, strlen($prefix) + 1);
                        }

                        if (isset($knownStorageKeys[$storageKeyNoPrefix])) {
                            continue;
                        }

                        $knownStorageKeys[$storageKeyNoPrefix] = true;

                        $downloadUrl = '';
                        try {
                            $downloadUrl = $uploadService->getDownloadPresignedUrl($storageKeyNoPrefix, 3600);
                        } catch (Exception $e) {
                            $downloadUrl = '';
                        }

                        $categories[$categoryName]['files'][] = [
                            'id' => 0,
                            'filename' => $obj['filename'] ?? basename($storageKeyNoPrefix),
                            'file_path' => $storageKeyNoPrefix,
                            'storage_key' => $storageKeyNoPrefix,
                            'download_url' => $downloadUrl,
                            'file_size' => (int)($obj['size'] ?? 0),
                            'approval_status' => 'approved',
                            'uploader_name' => '',
                            'create_time' => null,
                        ];
                    }
                }
            } catch (Exception $e) {
                // 忽略 S3 读取失败，继续返回 deliverables 数据
            }
        }
        
        return [
            'categories' => $categories,
            'total' => count($files),
        ];
    } catch (Exception $e) {
        return ['categories' => [], 'total' => 0];
    }
}

/**
 * 获取项目沟通记录
 */
function getProjectMessages($projectId) {
    try {
        $messages = Db::query("
            SELECT 
                m.id, m.content, m.message_type, m.create_time,
                u.realname as sender_name, u.role as sender_role
            FROM project_messages m
            LEFT JOIN users u ON m.user_id = u.id
            WHERE m.project_id = ?
            ORDER BY m.create_time DESC
            LIMIT 100
        ", [$projectId]);
        
        $result = [];
        foreach ($messages as $msg) {
            $result[] = [
                'id' => (int)$msg['id'],
                'content' => $msg['content'],
                'message_type' => $msg['message_type'],
                'sender_name' => $msg['sender_name'],
                'sender_role' => $msg['sender_role'],
                'create_time' => $msg['create_time'] ? date('Y-m-d H:i', $msg['create_time']) : null,
            ];
        }
        return $result;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * 获取项目时间线（操作记录）
 */
function getProjectTimeline($projectId) {
    try {
        $statusLogs = Db::query("
            SELECT 
                psl.from_status,
                psl.to_status,
                psl.changed_at as event_time,
                u.realname as operator_name
            FROM project_status_log psl
            LEFT JOIN users u ON psl.changed_by = u.id
            WHERE psl.project_id = ?
            ORDER BY psl.changed_at DESC
            LIMIT 50
        ", [$projectId]);
        
        $result = [];
        foreach ($statusLogs as $log) {
            $result[] = [
                'type' => 'status_change',
                'title' => '状态变更',
                'content' => ($log['from_status'] ?: '无') . ' → ' . $log['to_status'],
                'operator' => $log['operator_name'],
                'time' => $log['event_time'] ? date('Y-m-d H:i', $log['event_time']) : null,
            ];
        }
        
        return $result;
    } catch (Exception $e) {
        return [];
    }
}
