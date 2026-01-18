# WebSocket 服务启动脚本
# 检查服务是否已运行，如果没有则启动

$port = 8300
$phpPath = "C:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe"
$scriptPath = "D:\aiDDDDDDM\WWW\crmchonggou\index\scripts\websocket_server.php"
$workDir = "D:\aiDDDDDDM\WWW\crmchonggou\index"

# 检查端口是否被占用
$portInUse = netstat -ano | findstr ":$port"

if ($portInUse) {
    Write-Host "WebSocket 服务已在运行 (端口 $port)" -ForegroundColor Green
} else {
    Write-Host "正在启动 WebSocket 服务..." -ForegroundColor Yellow
    Start-Process -FilePath $phpPath -ArgumentList $scriptPath -WorkingDirectory $workDir -WindowStyle Hidden
    Start-Sleep -Seconds 2
    
    # 再次检查
    $portInUse = netstat -ano | findstr ":$port"
    if ($portInUse) {
        Write-Host "WebSocket 服务启动成功 (端口 $port)" -ForegroundColor Green
    } else {
        Write-Host "WebSocket 服务启动失败" -ForegroundColor Red
    }
}
