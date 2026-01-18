<# 
.SYNOPSIS
    桌面客户端一键打包脚本
.DESCRIPTION
    自动更新版本号、构建 Tauri 应用并复制到输出目录
.PARAMETER Version
    版本号，格式: MAJOR.MINOR.PATCH (默认: 自动递增)
.EXAMPLE
    .\scripts\build-desktop.ps1 -Version "1.0.1"
#>

param(
    [string]$Version = ""
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = Split-Path -Parent $ScriptDir
$DesktopDir = Join-Path $ProjectRoot "desktop"
$OutputDir = Join-Path $ProjectRoot "output"
$TauriConfig = Join-Path $DesktopDir "src-tauri\tauri.conf.json"

function Get-NextVersion {
    param([string]$Current)
    
    $parts = $Current -split '\.'
    $major = [int]$parts[0]
    $minor = [int]$parts[1]
    $patch = [int]$parts[2]
    
    $patch++
    if ($patch -ge 10) {
        $patch = 0
        $minor++
    }
    if ($minor -ge 10) {
        $minor = 0
        $major++
    }
    
    return "$major.$minor.$patch"
}

# 读取当前版本
$config = Get-Content $TauriConfig -Raw | ConvertFrom-Json
$currentVersion = $config.version

# 确定目标版本
if ([string]::IsNullOrEmpty($Version)) {
    $Version = Get-NextVersion -Current $currentVersion
    Write-Host "自动递增版本: $currentVersion -> $Version" -ForegroundColor Cyan
} else {
    Write-Host "使用指定版本: $Version" -ForegroundColor Cyan
}

# 更新版本号
$config.version = $Version
$config | ConvertTo-Json -Depth 10 | Set-Content $TauriConfig -Encoding UTF8
Write-Host "已更新 tauri.conf.json 版本号" -ForegroundColor Green

# 创建输出目录
if (-not (Test-Path $OutputDir)) {
    New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
    Write-Host "已创建输出目录: $OutputDir" -ForegroundColor Green
}

# 构建
Write-Host "`n开始构建..." -ForegroundColor Yellow
Push-Location $DesktopDir
try {
    npm run tauri build -- --no-bundle
    if ($LASTEXITCODE -ne 0) {
        throw "构建失败"
    }
} finally {
    Pop-Location
}

# 复制输出
$SourceExe = Join-Path $DesktopDir "src-tauri\target\release\tech-resource-sync.exe"
$TargetExe = Join-Path $OutputDir "tech-resource-sync-$Version.exe"

Copy-Item $SourceExe $TargetExe -Force
Write-Host "`n构建完成!" -ForegroundColor Green
Write-Host "输出文件: $TargetExe" -ForegroundColor Cyan

# 显示文件信息
$fileInfo = Get-Item $TargetExe
Write-Host "文件大小: $([math]::Round($fileInfo.Length / 1MB, 2)) MB" -ForegroundColor Cyan
