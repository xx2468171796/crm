<?php
/**
 * 上传配置诊断页面
 * 检查 PHP 和服务器配置是否支持大文件上传
 */

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/rbac.php';

auth_require();
$user = current_user();

if (!isAdmin($user)) {
    layout_header('无权访问');
    echo '<div class="alert alert-danger">仅管理员可查看上传配置诊断结果。</div>';
    layout_footer();
    exit;
}

// 诊断函数
function formatBytes($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

function parseSize($size) {
    $size = trim($size);
    $last = strtolower($size[strlen($size) - 1]);
    $size = (int)$size;
    
    switch ($last) {
        case 'g':
            $size *= 1024;
        case 'm':
            $size *= 1024;
        case 'k':
            $size *= 1024;
    }
    
    return $size;
}

// 执行诊断
$config = [
    'php' => [
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'max_execution_time' => ini_get('max_execution_time'),
        'max_input_time' => ini_get('max_input_time'),
        'memory_limit' => ini_get('memory_limit'),
        'file_uploads' => ini_get('file_uploads'),
        'upload_tmp_dir' => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
    ],
    'parsed' => [
        'upload_max_filesize_bytes' => parseSize(ini_get('upload_max_filesize')),
        'post_max_size_bytes' => parseSize(ini_get('post_max_size')),
    ],
    'issues' => [],
    'recommendations' => [],
];

// 检查配置问题
if ($config['parsed']['post_max_size_bytes'] < $config['parsed']['upload_max_filesize_bytes']) {
    $config['issues'][] = 'post_max_size 必须大于或等于 upload_max_filesize';
    $config['recommendations'][] = '在 php.ini 中设置: post_max_size = ' . ini_get('upload_max_filesize');
}

if ($config['parsed']['upload_max_filesize_bytes'] < 100 * 1024 * 1024) {
    $config['issues'][] = 'upload_max_filesize 小于 100MB，可能无法上传大文件';
    $config['recommendations'][] = '建议设置 upload_max_filesize = 2048M 或更大';
}

if ($config['parsed']['post_max_size_bytes'] < 100 * 1024 * 1024) {
    $config['issues'][] = 'post_max_size 小于 100MB，可能无法上传大文件';
    $config['recommendations'][] = '建议设置 post_max_size = 2048M 或更大';
}

if ($config['php']['max_execution_time'] < 300) {
    $config['issues'][] = 'max_execution_time 小于 300 秒，大文件上传可能超时';
    $config['recommendations'][] = '建议设置 max_execution_time = 300 或更大';
}

if ($config['php']['max_input_time'] < 300) {
    $config['issues'][] = 'max_input_time 小于 300 秒，大文件上传可能超时';
    $config['recommendations'][] = '建议设置 max_input_time = 300 或更大';
}

// 检查临时目录
$tmpDir = $config['php']['upload_tmp_dir'];
if (!is_dir($tmpDir)) {
    $config['issues'][] = "上传临时目录不存在: {$tmpDir}";
} elseif (!is_writable($tmpDir)) {
    $config['issues'][] = "上传临时目录不可写: {$tmpDir}";
} else {
    $freeSpace = disk_free_space($tmpDir);
    $config['tmp_dir_free_space'] = formatBytes($freeSpace);
    if ($freeSpace < 5 * 1024 * 1024 * 1024) {
        $config['issues'][] = "临时目录可用空间小于 5GB，可能影响大文件上传";
    }
}

// 检查应用配置
$storageConfigFile = __DIR__ . '/../config/storage.php';
if (file_exists($storageConfigFile)) {
    $storageConfig = require $storageConfigFile;
    $appMaxSize = $storageConfig['limits']['max_single_size'] ?? 0;
    $config['app_config'] = [
        'max_single_size' => formatBytes($appMaxSize),
        'max_single_size_bytes' => $appMaxSize,
    ];
    
    if ($appMaxSize > $config['parsed']['upload_max_filesize_bytes']) {
        $config['issues'][] = '应用配置的最大文件大小 (' . formatBytes($appMaxSize) . ') 超过了 PHP upload_max_filesize (' . $config['php']['upload_max_filesize'] . ')';
        $config['recommendations'][] = '建议将 PHP upload_max_filesize 设置为至少 ' . formatBytes($appMaxSize);
    }
}

$config['status'] = empty($config['issues']) ? 'ok' : 'warning';
$config['message'] = empty($config['issues']) 
    ? '配置检查通过，支持大文件上传' 
    : '发现 ' . count($config['issues']) . ' 个潜在问题';

layout_header('上传配置诊断');
?>

<div class="row">
    <div class="col-lg-10 col-xl-8">
        <div class="card mb-4 shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-1">上传配置诊断</h5>
                    <small class="text-muted">
                        检查 PHP 和服务器配置是否支持大文件上传
                    </small>
                </div>
                <div class="d-flex gap-2">
                    <a href="index.php?page=upload_config_check" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-clockwise"></i> 重新检测
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($config)): ?>
                    <div class="alert alert-warning mb-4">
                        ⚠️ 无法获取配置信息
                    </div>
                <?php else: ?>
                    <?php if ($config['status'] === 'ok'): ?>
                        <div class="alert alert-success mb-4">
                            ✅ <?= htmlspecialchars($config['message']) ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning mb-4">
                            ⚠️ <?= htmlspecialchars($config['message']) ?>
                        </div>
                    <?php endif; ?>

                    <h6 class="mt-4 mb-3">PHP 配置</h6>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                            <tr>
                                <th style="width: 30%;">配置项</th>
                                <th style="width: 25%;">当前值</th>
                                <th style="width: 25%;">字节大小</th>
                                <th>说明</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <td><code>upload_max_filesize</code></td>
                                <td><?= htmlspecialchars($config['php']['upload_max_filesize'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars(number_format($config['parsed']['upload_max_filesize_bytes'] ?? 0)) ?> bytes</td>
                                <td>单个文件最大上传大小</td>
                            </tr>
                            <tr>
                                <td><code>post_max_size</code></td>
                                <td><?= htmlspecialchars($config['php']['post_max_size'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars(number_format($config['parsed']['post_max_size_bytes'] ?? 0)) ?> bytes</td>
                                <td>POST 请求最大大小（必须 ≥ upload_max_filesize）</td>
                            </tr>
                            <tr>
                                <td><code>max_execution_time</code></td>
                                <td><?= htmlspecialchars($config['php']['max_execution_time'] ?? 'N/A') ?> 秒</td>
                                <td>-</td>
                                <td>脚本最大执行时间</td>
                            </tr>
                            <tr>
                                <td><code>max_input_time</code></td>
                                <td><?= htmlspecialchars($config['php']['max_input_time'] ?? 'N/A') ?> 秒</td>
                                <td>-</td>
                                <td>脚本解析输入数据的最大时间</td>
                            </tr>
                            <tr>
                                <td><code>memory_limit</code></td>
                                <td><?= htmlspecialchars($config['php']['memory_limit'] ?? 'N/A') ?></td>
                                <td>-</td>
                                <td>PHP 内存限制</td>
                            </tr>
                            <tr>
                                <td><code>file_uploads</code></td>
                                <td><?= htmlspecialchars($config['php']['file_uploads'] ? '启用' : '禁用') ?></td>
                                <td>-</td>
                                <td>是否允许文件上传</td>
                            </tr>
                            <tr>
                                <td><code>upload_tmp_dir</code></td>
                                <td><?= htmlspecialchars($config['php']['upload_tmp_dir'] ?? 'N/A') ?></td>
                                <td><?= isset($config['tmp_dir_free_space']) ? htmlspecialchars($config['tmp_dir_free_space']) : '-' ?></td>
                                <td>上传临时目录（可用空间：<?= isset($config['tmp_dir_free_space']) ? htmlspecialchars($config['tmp_dir_free_space']) : '未知' ?>）</td>
                            </tr>
                            </tbody>
                        </table>
                    </div>

                    <?php if (isset($config['app_config'])): ?>
                        <h6 class="mt-4 mb-3">应用配置</h6>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                <tr>
                                    <th style="width: 30%;">配置项</th>
                                    <th style="width: 25%;">当前值</th>
                                    <th>说明</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr>
                                    <td><code>max_single_size</code></td>
                                    <td><?= htmlspecialchars($config['app_config']['max_single_size'] ?? 'N/A') ?></td>
                                    <td>应用允许的单文件最大大小</td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($config['issues'])): ?>
                        <h6 class="mt-4 mb-3 text-danger">⚠️ 发现的问题</h6>
                        <ul class="list-group mb-3">
                            <?php foreach ($config['issues'] as $issue): ?>
                                <li class="list-group-item list-group-item-danger">
                                    <?= htmlspecialchars($issue) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if (!empty($config['recommendations'])): ?>
                        <h6 class="mt-4 mb-3 text-primary">💡 建议</h6>
                        <ul class="list-group mb-3">
                            <?php foreach ($config['recommendations'] as $rec): ?>
                                <li class="list-group-item list-group-item-info">
                                    <?= htmlspecialchars($rec) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4 shadow-sm">
            <div class="card-header">
                <h5 class="mb-1">大文件上传测试</h5>
                <small class="text-muted">
                    测试实际的大文件上传功能，验证配置是否正常工作
                </small>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="testFileSize" class="form-label">选择测试文件大小</label>
                    <div class="input-group">
                        <select id="testFileSize" class="form-select">
                            <option value="10485760">10 MB</option>
                            <option value="52428800">50 MB</option>
                            <option value="104857600" selected>100 MB</option>
                            <option value="209715200">200 MB</option>
                            <option value="524288000">500 MB</option>
                            <option value="custom">自定义大小</option>
                        </select>
                        <input type="text" id="customFileSize" class="form-control" placeholder="输入大小（MB）" style="display: none;">
                    </div>
                    <small class="form-text text-muted">选择或输入要测试的文件大小</small>
                </div>

                <div class="mb-3">
                    <label for="testCustomerId" class="form-label">测试客户ID</label>
                    <input type="number" id="testCustomerId" class="form-control" placeholder="输入客户ID（用于测试上传）" min="1">
                    <small class="form-text text-muted">需要输入一个有效的客户ID才能进行上传测试</small>
                </div>

                <button id="startTestBtn" class="btn btn-primary">
                    <i class="bi bi-play-circle"></i> 开始测试
                </button>

                <div id="testProgress" class="mt-4" style="display: none;">
                    <div class="mb-2">
                        <strong>测试进度：</strong>
                        <span id="testStatus">准备中...</span>
                    </div>
                    <div class="progress mb-3" style="height: 25px;">
                        <div id="testProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" 
                             role="progressbar" style="width: 0%">0%</div>
                    </div>
                    <div id="testDetails" class="small text-muted"></div>
                </div>

                <div id="testResult" class="mt-4" style="display: none;"></div>
            </div>
        </div>

        <div class="alert alert-warning">
            <strong><i class="bi bi-exclamation-triangle"></i> 重要提示：</strong>
            <p class="mb-2">如果测试时 10MB 成功但 50MB 失败，通常是 <strong>Nginx 的 client_max_body_size 限制</strong>导致的。</p>
            <p class="mb-2"><strong>快速修复：</strong></p>
            <ol class="mb-3">
                <li>找到 Nginx 配置文件（通常在 <code>/etc/nginx/nginx.conf</code> 或站点配置文件中）</li>
                <li>在 <code>server</code> 或 <code>http</code> 块中添加或修改：<code>client_max_body_size 2048m;</code></li>
                <li>保存后重新加载 Nginx：
                    <ul>
                        <li>标准 Nginx：<code>nginx -s reload</code> 或 <code>systemctl reload nginx</code></li>
                        <li>OpenResty：<code>openresty -s reload</code> 或 <code>systemctl reload openresty</code></li>
                        <li>1Panel：在网站设置中修改"客户端上传大小限制"</li>
                    </ul>
                </li>
            </ol>
        </div>
        
        <div class="alert alert-info">
            <strong>详细配置说明：</strong>
            <ul class="mb-0">
                <li><strong>修改 PHP 配置：</strong>
                    <ul>
                        <li>找到 PHP 配置文件 <code>php.ini</code>（可通过 <code>php --ini</code> 命令查找）</li>
                        <li>修改相关配置项后，重启 PHP-FPM 或 Web 服务器</li>
                        <li>对于 Nginx + PHP-FPM，通常需要重启 PHP-FPM：<code>systemctl restart php-fpm</code></li>
                    </ul>
                </li>
                <li><strong>检查 Nginx 配置：</strong>
                    <ul>
                        <li>确保 Nginx 配置中有 <code>client_max_body_size 2048m;</code> 或更大</li>
                        <li>修改后需要重新加载 Nginx：<code>nginx -s reload</code></li>
                        <li><strong>注意：</strong>如果配置文件中没有设置，默认值通常是 1MB，这就是为什么 50MB 会失败</li>
                    </ul>
                </li>
                <li><strong>常见错误码：</strong>
                    <ul>
                        <li><strong>HTTP 413：</strong>Nginx <code>client_max_body_size</code> 限制（最常见）</li>
                        <li><strong>HTTP 500：</strong>PHP <code>post_max_size</code> 或 <code>upload_max_filesize</code> 限制</li>
                        <li><strong>HTTP 408/504：</strong>上传超时，检查 <code>max_execution_time</code> 和 <code>max_input_time</code></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</div>

<script>
(function() {
    const testFileSizeSelect = document.getElementById('testFileSize');
    const customFileSizeInput = document.getElementById('customFileSize');
    const testCustomerIdInput = document.getElementById('testCustomerId');
    const startTestBtn = document.getElementById('startTestBtn');
    const testProgress = document.getElementById('testProgress');
    const testStatus = document.getElementById('testStatus');
    const testProgressBar = document.getElementById('testProgressBar');
    const testDetails = document.getElementById('testDetails');
    const testResult = document.getElementById('testResult');

    // 切换自定义大小输入框
    testFileSizeSelect.addEventListener('change', function() {
        if (this.value === 'custom') {
            customFileSizeInput.style.display = 'block';
            customFileSizeInput.focus();
        } else {
            customFileSizeInput.style.display = 'none';
        }
    });

    // 格式化字节大小
    function formatBytes(bytes) {
        if (bytes >= 1073741824) {
            return (bytes / 1073741824).toFixed(2) + ' GB';
        } else if (bytes >= 1048576) {
            return (bytes / 1048576).toFixed(2) + ' MB';
        } else if (bytes >= 1024) {
            return (bytes / 1024).toFixed(2) + ' KB';
        }
        return bytes + ' bytes';
    }

    // 生成测试文件
    function generateTestFile(sizeBytes) {
        const chunkSize = 1024 * 1024; // 1MB chunks
        const chunks = Math.ceil(sizeBytes / chunkSize);
        const blobParts = [];
        
        // 生成随机数据块
        for (let i = 0; i < chunks; i++) {
            const remaining = Math.min(chunkSize, sizeBytes - i * chunkSize);
            const array = new Uint8Array(remaining);
            // 使用随机数据填充
            for (let j = 0; j < remaining; j++) {
                array[j] = Math.floor(Math.random() * 256);
            }
            blobParts.push(array);
        }
        
        return new Blob(blobParts, { type: 'application/octet-stream' });
    }

    // 开始测试
    startTestBtn.addEventListener('click', async function() {
        const customerId = parseInt(testCustomerIdInput.value);
        if (!customerId || customerId < 1) {
            alert('请输入有效的客户ID');
            return;
        }

        // 获取文件大小
        let sizeBytes;
        if (testFileSizeSelect.value === 'custom') {
            const sizeMB = parseFloat(customFileSizeInput.value);
            if (!sizeMB || sizeMB <= 0) {
                alert('请输入有效的文件大小（MB）');
                return;
            }
            sizeBytes = Math.floor(sizeMB * 1024 * 1024);
        } else {
            sizeBytes = parseInt(testFileSizeSelect.value);
        }

        // 检查文件大小限制
        const maxSize = <?= $config['parsed']['upload_max_filesize_bytes'] ?? 0 ?>;
        if (maxSize > 0 && sizeBytes > maxSize) {
            if (!confirm(`测试文件大小 (${formatBytes(sizeBytes)}) 超过了 upload_max_filesize (${formatBytes(maxSize)})，可能会失败。是否继续？`)) {
                return;
            }
        }

        // 禁用按钮
        startTestBtn.disabled = true;
        startTestBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> 测试中...';
        
        // 显示进度
        testProgress.style.display = 'block';
        testResult.style.display = 'none';
        testStatus.textContent = '正在生成测试文件...';
        testProgressBar.style.width = '10%';
        testProgressBar.textContent = '10%';
        testDetails.textContent = '';

        const startTime = Date.now();
        let fileGenTime = 0;
        let uploadTime = 0;

        try {
            // 生成测试文件
            const genStartTime = Date.now();
            testStatus.textContent = `正在生成 ${formatBytes(sizeBytes)} 的测试文件...`;
            testDetails.textContent = '这可能需要几秒钟...';
            
            const testFile = generateTestFile(sizeBytes);
            fileGenTime = Date.now() - genStartTime;
            
            testStatus.textContent = '测试文件已生成，正在上传...';
            testProgressBar.style.width = '30%';
            testProgressBar.textContent = '30%';
            testDetails.textContent = `文件生成耗时: ${(fileGenTime / 1000).toFixed(2)} 秒`;

            // 创建 FormData
            const formData = new FormData();
            formData.append('customer_id', customerId);
            formData.append('category', 'test');
            formData.append('files[]', testFile, `test_${sizeBytes}_${Date.now()}.bin`);

            // 上传文件
            const uploadStartTime = Date.now();
            testStatus.textContent = '正在上传文件...';
            testProgressBar.style.width = '50%';
            testProgressBar.textContent = '50%';

            const xhr = new XMLHttpRequest();
            
            // 监听上传进度
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    const percent = 50 + (e.loaded / e.total) * 50; // 50-100%
                    testProgressBar.style.width = percent + '%';
                    testProgressBar.textContent = Math.round(percent) + '%';
                    const uploaded = formatBytes(e.loaded);
                    const total = formatBytes(e.total);
                    const speed = e.loaded / ((Date.now() - uploadStartTime) / 1000);
                    testDetails.textContent = `已上传: ${uploaded} / ${total} (${formatBytes(speed)}/秒)`;
                }
            });

            // 处理响应
            const response = await new Promise((resolve, reject) => {
                xhr.addEventListener('load', function() {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        try {
                            resolve(JSON.parse(xhr.responseText));
                        } catch (e) {
                            resolve({ success: false, message: '响应解析失败: ' + xhr.responseText.substring(0, 200) });
                        }
                    } else {
                        // 针对不同HTTP状态码提供详细错误信息
                        let errorMsg = `HTTP ${xhr.status}: ${xhr.statusText}`;
                        let errorDetails = null;
                        
                        if (xhr.status === 413) {
                            errorMsg = 'HTTP 413: Request Entity Too Large（请求实体过大）';
                            errorDetails = {
                                title: 'Nginx 配置限制',
                                description: '这是 Nginx 的 client_max_body_size 限制导致的。',
                                solution: [
                                    '找到 Nginx 配置文件（通常在 /etc/nginx/nginx.conf 或站点配置文件中）',
                                    '在 server 或 http 块中添加或修改：client_max_body_size 2048m;',
                                    '保存后重新加载 Nginx：nginx -s reload 或 systemctl reload nginx',
                                    '如果使用 OpenResty：openresty -s reload 或 systemctl reload openresty',
                                    '如果使用 1Panel，在网站设置中修改"客户端上传大小限制"'
                                ],
                                estimatedLimit: '根据测试结果，当前限制可能在 10-50MB 之间'
                            };
                        } else if (xhr.status === 500) {
                            errorMsg = 'HTTP 500: Internal Server Error（服务器内部错误）';
                            errorDetails = {
                                title: 'PHP 配置或服务器错误',
                                description: '可能是 PHP 配置限制或服务器处理错误。',
                                solution: [
                                    '检查 PHP 配置：upload_max_filesize 和 post_max_size',
                                    '查看服务器错误日志获取详细信息',
                                    '确保 post_max_size >= upload_max_filesize',
                                    '检查临时目录是否有足够空间和写权限'
                                ]
                            };
                        } else if (xhr.status === 408 || xhr.status === 504) {
                            errorMsg = `HTTP ${xhr.status}: 请求超时`;
                            errorDetails = {
                                title: '上传超时',
                                description: '文件上传时间过长，可能被服务器或代理超时。',
                                solution: [
                                    '检查 PHP max_execution_time 和 max_input_time 配置',
                                    '检查 Nginx fastcgi_read_timeout 配置',
                                    '如果使用代理，检查代理服务器的超时设置',
                                    '考虑使用分片上传功能'
                                ]
                            };
                        }
                        
                        const error = new Error(errorMsg);
                        error.status = xhr.status;
                        error.details = errorDetails;
                        reject(error);
                    }
                });

                xhr.addEventListener('error', function() {
                    reject(new Error('网络错误，上传失败'));
                });

                xhr.addEventListener('timeout', function() {
                    reject(new Error('上传超时'));
                });

                xhr.timeout = 600000; // 10分钟超时
                xhr.open('POST', '../api/customer_files.php');
                xhr.send(formData);
            });

            uploadTime = Date.now() - uploadStartTime;
            const totalTime = Date.now() - startTime;
            const uploadSpeed = sizeBytes / (uploadTime / 1000);

            // 显示结果
            testProgress.style.display = 'none';
            testResult.style.display = 'block';

            if (response.success) {
                testResult.className = 'alert alert-success';
                testResult.innerHTML = `
                    <h6><i class="bi bi-check-circle"></i> 测试成功！</h6>
                    <table class="table table-sm mt-3">
                        <tr>
                            <td><strong>文件大小：</strong></td>
                            <td>${formatBytes(sizeBytes)}</td>
                        </tr>
                        <tr>
                            <td><strong>文件生成耗时：</strong></td>
                            <td>${(fileGenTime / 1000).toFixed(2)} 秒</td>
                        </tr>
                        <tr>
                            <td><strong>上传耗时：</strong></td>
                            <td>${(uploadTime / 1000).toFixed(2)} 秒</td>
                        </tr>
                        <tr>
                            <td><strong>总耗时：</strong></td>
                            <td>${(totalTime / 1000).toFixed(2)} 秒</td>
                        </tr>
                        <tr>
                            <td><strong>上传速度：</strong></td>
                            <td>${formatBytes(uploadSpeed)}/秒</td>
                        </tr>
                        <tr>
                            <td><strong>上传的文件数：</strong></td>
                            <td>${response.data?.uploaded_count || 0}</td>
                        </tr>
                    </table>
                `;
            } else {
                testResult.className = 'alert alert-danger';
                let errorMessage = response.message || '未知错误';
                let errorDetails = null;
                
                // 检查错误信息中是否包含413或大小限制相关的内容
                if (errorMessage.includes('413') || errorMessage.toLowerCase().includes('too large') || 
                    errorMessage.toLowerCase().includes('request entity too large')) {
                    errorDetails = {
                        title: 'Nginx 配置限制',
                        description: '这是 Nginx 的 client_max_body_size 限制导致的。',
                        solution: [
                            '找到 Nginx 配置文件（通常在 /etc/nginx/nginx.conf 或站点配置文件中）',
                            '在 server 或 http 块中添加或修改：client_max_body_size 2048m;',
                            '保存后重新加载 Nginx：nginx -s reload 或 systemctl reload nginx',
                            '如果使用 OpenResty：openresty -s reload 或 systemctl reload openresty',
                            '如果使用 1Panel，在网站设置中修改"客户端上传大小限制"'
                        ],
                        estimatedLimit: '根据测试结果，当前限制可能在 10-50MB 之间'
                    };
                }
                
                let errorHtml = `
                    <h6><i class="bi bi-x-circle"></i> 测试失败</h6>
                    <p><strong>错误信息：</strong> ${errorMessage}</p>
                    <p><strong>上传耗时：</strong> ${(uploadTime / 1000).toFixed(2)} 秒</p>
                `;
                
                if (errorDetails) {
                    errorHtml += `
                        <div class="mt-3 p-3 bg-light rounded border-start border-danger border-3">
                            <h6 class="text-danger mb-2"><i class="bi bi-exclamation-triangle"></i> ${errorDetails.title}</h6>
                            <p class="mb-2">${errorDetails.description}</p>
                            ${errorDetails.estimatedLimit ? `<p class="text-muted small mb-2"><i class="bi bi-info-circle"></i> ${errorDetails.estimatedLimit}</p>` : ''}
                            <strong class="d-block mb-2">解决方案：</strong>
                            <ol class="mb-0 small">
                                ${errorDetails.solution.map(step => `<li>${step}</li>`).join('')}
                            </ol>
                        </div>
                    `;
                } else {
                    errorHtml += `
                        <div class="mt-3">
                            <p class="mb-2"><strong>排查建议：</strong></p>
                            <ul class="small mb-0">
                                <li>如果错误是 HTTP 413，通常是 Nginx <code>client_max_body_size</code> 限制，需要修改 Nginx 配置</li>
                                <li>如果错误是 HTTP 500，检查 PHP 配置（<code>upload_max_filesize</code>, <code>post_max_size</code>）</li>
                                <li>检查服务器错误日志获取更详细的错误信息</li>
                                <li>确保网络连接稳定</li>
                            </ul>
                        </div>
                    `;
                }
                
                testResult.innerHTML = errorHtml;
            }
        } catch (error) {
            const totalTime = Date.now() - startTime;
            testProgress.style.display = 'none';
            testResult.style.display = 'block';
            testResult.className = 'alert alert-danger';
            
            let errorHtml = `
                <h6><i class="bi bi-x-circle"></i> 测试失败</h6>
                <p><strong>错误信息：</strong> ${error.message}</p>
                <p><strong>总耗时：</strong> ${(totalTime / 1000).toFixed(2)} 秒</p>
            `;
            
            // 如果有详细错误信息，显示诊断和解决方案
            if (error.details) {
                errorHtml += `
                    <div class="mt-3 p-3 bg-light rounded border-start border-danger border-3">
                        <h6 class="text-danger mb-2"><i class="bi bi-exclamation-triangle"></i> ${error.details.title}</h6>
                        <p class="mb-2">${error.details.description}</p>
                        ${error.details.estimatedLimit ? `<p class="text-muted small mb-2"><i class="bi bi-info-circle"></i> ${error.details.estimatedLimit}</p>` : ''}
                        <strong class="d-block mb-2">解决方案：</strong>
                        <ol class="mb-0 small">
                            ${error.details.solution.map(step => `<li>${step}</li>`).join('')}
                        </ol>
                    </div>
                `;
            } else {
                // 通用错误提示
                errorHtml += `
                    <div class="mt-3">
                        <p class="mb-2"><strong>排查建议：</strong></p>
                        <ul class="small mb-0">
                            <li>如果错误是 HTTP 413，通常是 Nginx <code>client_max_body_size</code> 限制，需要修改 Nginx 配置</li>
                            <li>如果错误是 HTTP 500，检查 PHP 配置（<code>upload_max_filesize</code>, <code>post_max_size</code>）</li>
                            <li>检查服务器错误日志获取更详细的错误信息</li>
                            <li>确保网络连接稳定</li>
                        </ul>
                    </div>
                `;
            }
            
            testResult.innerHTML = errorHtml;
        } finally {
            // 恢复按钮
            startTestBtn.disabled = false;
            startTestBtn.innerHTML = '<i class="bi bi-play-circle"></i> 开始测试';
        }
    });
})();
</script>

<?php
layout_footer();

