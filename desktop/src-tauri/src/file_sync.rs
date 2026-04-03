use std::path::PathBuf;
use std::sync::Mutex;
use tauri::{AppHandle, Manager};
use serde::{Deserialize, Serialize};

/// 同步规则
#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum SyncRule {
    /// 自动下载，不上传（客户文件）
    DownloadOnly,
    /// 自动双向同步（作品文件）
    Bidirectional,
    /// 手动上传（模型文件）
    ManualUpload,
}

/// 文件夹类型
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct FolderConfig {
    pub name: String,
    pub rule: SyncRule,
}

/// 项目目录结构
pub const PROJECT_FOLDERS: [(&str, &str); 3] = [
    ("客户文件", "download_only"),
    ("作品文件", "bidirectional"),
    ("模型文件", "manual_upload"),
];

/// 文件同步状态
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct SyncStatus {
    pub is_syncing: bool,
    pub last_sync: Option<String>,
    pub pending_uploads: u32,
    pub pending_downloads: u32,
}

/// 创建项目目录结构
#[tauri::command]
pub async fn create_project_folders(
    work_dir: String,
    project_name: String,
) -> Result<Vec<String>, String> {
    let mut normalized_project_name = project_name;
    if !normalized_project_name.contains('/') && !normalized_project_name.contains('\\') {
        if let Some(pos) = normalized_project_name.rfind('_') {
            let (left, right) = normalized_project_name.split_at(pos);
            let right = &right[1..];
            if right.starts_with('Q') && right[1..].chars().all(|c| c.is_ascii_digit()) {
                normalized_project_name = format!("{}_{}", right, left);
            }
        }
        normalized_project_name = normalized_project_name
            .replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], "_");
    }

    let base_path = PathBuf::from(&work_dir).join(&normalized_project_name);
    let mut created_paths = Vec::new();

    // 创建项目根目录
    if !base_path.exists() {
        std::fs::create_dir_all(&base_path)
            .map_err(|e| format!("创建项目目录失败: {}", e))?;
        created_paths.push(base_path.to_string_lossy().to_string());
    }

    // 创建子目录
    for (folder_name, _rule) in PROJECT_FOLDERS.iter() {
        let folder_path = base_path.join(folder_name);
        if !folder_path.exists() {
            std::fs::create_dir_all(&folder_path)
                .map_err(|e| format!("创建目录 {} 失败: {}", folder_name, e))?;
            created_paths.push(folder_path.to_string_lossy().to_string());
        }
    }

    Ok(created_paths)
}

/// 获取同步规则
#[tauri::command]
pub fn get_sync_rule(folder_type: String) -> String {
    match folder_type.as_str() {
        "客户文件" => "download_only".to_string(),
        "作品文件" => "bidirectional".to_string(),
        "模型文件" => "manual_upload".to_string(),
        _ => "unknown".to_string(),
    }
}

/// 重命名下载的模型文件
/// 格式: 云端_{原文件名}_{上传人}_{上传日期}.{扩展名}
#[tauri::command]
pub fn rename_model_file(
    original_name: String,
    uploader: String,
    upload_date: String,
) -> String {
    let path = PathBuf::from(&original_name);
    let stem = path.file_stem()
        .map(|s| s.to_string_lossy().to_string())
        .unwrap_or_else(|| original_name.clone());
    let ext = path.extension()
        .map(|s| format!(".{}", s.to_string_lossy()))
        .unwrap_or_default();
    
    // 清理日期格式 (去掉连字符)
    let date_clean = upload_date.replace("-", "");
    
    format!("云端_{}_{}_{}{}",stem, uploader, date_clean, ext)
}

/// 检查文件是否需要同步
#[tauri::command]
pub fn should_sync_file(folder_type: String, is_upload: bool) -> bool {
    match folder_type.as_str() {
        "客户文件" => !is_upload, // 只下载
        "作品文件" => true, // 双向同步
        "模型文件" => false, // 不自动同步，需手动
        _ => false,
    }
}

/// 获取同步状态
#[tauri::command]
pub fn get_sync_status() -> SyncStatus {
    SyncStatus {
        is_syncing: false,
        last_sync: None,
        pending_uploads: 0,
        pending_downloads: 0,
    }
}

/// 打开项目文件夹
/// sub_folder: 可选，指定子文件夹（客户文件/作品文件/模型文件）
#[tauri::command]
pub async fn open_project_folder(
    work_dir: String,
    project_name: String,
    sub_folder: Option<String>,
) -> Result<(), String> {
    // 调试日志：打印入参
    log::info!("[OpenFolder] 入参: work_dir={:?}, project_name={:?}, sub_folder={:?}", 
        work_dir, project_name, sub_folder);
    
    let mut normalized_project_name = project_name.clone();
    if !normalized_project_name.contains('/') && !normalized_project_name.contains('\\') {
        if let Some(pos) = normalized_project_name.rfind('_') {
            let (left, right) = normalized_project_name.split_at(pos);
            let right = &right[1..];
            if right.starts_with('Q') && right[1..].chars().all(|c| c.is_ascii_digit()) {
                normalized_project_name = format!("{}_{}", right, left);
            }
        }
        normalized_project_name = normalized_project_name
            .replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], "_");
    }
    
    log::info!("[OpenFolder] 规范化后 project_name={:?}", normalized_project_name);

    let project_path = PathBuf::from(&work_dir).join(&normalized_project_name);
    log::info!("[OpenFolder] project_path={:?}", project_path);
    
    // 确保项目根目录存在
    if !project_path.exists() {
        std::fs::create_dir_all(&project_path)
            .map_err(|e| format!("创建项目目录失败: {}", e))?;
    }
    
    // 自动创建三个标准子文件夹
    let standard_folders = ["客户文件", "作品文件", "模型文件"];
    for folder in &standard_folders {
        let sub_path = project_path.join(folder);
        if !sub_path.exists() {
            std::fs::create_dir_all(&sub_path)
                .map_err(|e| format!("创建子目录 {} 失败: {}", folder, e))?;
        }
    }
    
    // 确定要打开的路径
    let mut path = project_path.clone();
    if let Some(sub) = sub_folder {
        path = project_path.join(&sub);
        // 确保指定的子文件夹存在
        if !path.exists() {
            std::fs::create_dir_all(&path)
                .map_err(|e| format!("创建目录失败: {}", e))?;
        }
    }

    #[cfg(target_os = "windows")]
    {
        let path_str = path.to_string_lossy().replace('/', "\\");
        log::info!("[OpenFolder] 最终打开路径: {:?}", path_str);
        std::process::Command::new("C:\\Windows\\explorer.exe")
            .arg(&path_str)
            .spawn()
            .map_err(|e| format!("打开文件夹失败: {}", e))?;
    }

    #[cfg(target_os = "macos")]
    {
        std::process::Command::new("open")
            .arg(&path)
            .spawn()
            .map_err(|e| format!("打开文件夹失败: {}", e))?;
    }

    #[cfg(target_os = "linux")]
    {
        std::process::Command::new("xdg-open")
            .arg(&path)
            .spawn()
            .map_err(|e| format!("打开文件夹失败: {}", e))?;
    }

    Ok(())
}

/// 检查项目文件夹是否存在
#[tauri::command]
pub fn project_folder_exists(
    work_dir: String,
    project_name: String,
) -> bool {
    let mut normalized_project_name = project_name;
    if !normalized_project_name.contains('/') && !normalized_project_name.contains('\\') {
        if let Some(pos) = normalized_project_name.rfind('_') {
            let (left, right) = normalized_project_name.split_at(pos);
            let right = &right[1..];
            if right.starts_with('Q') && right[1..].chars().all(|c| c.is_ascii_digit()) {
                normalized_project_name = format!("{}_{}", right, left);
            }
        }
        normalized_project_name = normalized_project_name
            .replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], "_");
    }

    let path = PathBuf::from(&work_dir).join(&normalized_project_name);
    path.exists()
}
