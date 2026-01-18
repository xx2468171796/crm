use serde::{Deserialize, Serialize};
use std::fs::{self, File};
use std::io::Write;
use std::path::Path;
use tauri::{AppHandle, Emitter};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DownloadProgress {
    pub task_id: String,
    pub downloaded: u64,
    pub total: u64,
    pub speed: u64,
    pub status: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DownloadResult {
    pub task_id: String,
    pub success: bool,
    pub file_path: String,
    pub error: Option<String>,
}

#[tauri::command]
pub async fn download_file(
    app: AppHandle,
    task_id: String,
    url: String,
    save_path: String,
) -> Result<DownloadResult, String> {
    let path = Path::new(&save_path);
    
    if let Some(parent) = path.parent() {
        if !parent.exists() {
            fs::create_dir_all(parent)
                .map_err(|e| format!("创建目录失败: {}", e))?;
        }
    }

    let client = reqwest::Client::new();
    
    let response = client
        .get(&url)
        .send()
        .await
        .map_err(|e| format!("请求失败: {}", e))?;

    if !response.status().is_success() {
        return Err(format!("HTTP 错误: {}", response.status()));
    }

    let total = response.content_length().unwrap_or(0);

    if total > 0 && path.exists() {
        if let Ok(meta) = fs::metadata(path) {
            if meta.is_file() && meta.len() == total {
                let _ = app.emit("download-progress", DownloadProgress {
                    task_id: task_id.clone(),
                    downloaded: total,
                    total,
                    speed: 0,
                    status: "skipped".to_string(),
                });

                return Ok(DownloadResult {
                    task_id,
                    success: true,
                    file_path: save_path,
                    error: None,
                });
            }
        }
    }
    
    let _ = app.emit("download-progress", DownloadProgress {
        task_id: task_id.clone(),
        downloaded: 0,
        total,
        speed: 0,
        status: "downloading".to_string(),
    });

    let mut file = File::create(&save_path)
        .map_err(|e| format!("创建文件失败: {}", e))?;

    let mut downloaded: u64 = 0;
    let mut last_emit_time = std::time::Instant::now();
    let mut last_downloaded: u64 = 0;

    let bytes = response.bytes().await
        .map_err(|e| format!("下载失败: {}", e))?;
    
    downloaded = bytes.len() as u64;
    
    file.write_all(&bytes)
        .map_err(|e| format!("写入文件失败: {}", e))?;

    let elapsed = last_emit_time.elapsed().as_secs_f64();
    let speed = if elapsed > 0.0 {
        ((downloaded - last_downloaded) as f64 / elapsed) as u64
    } else {
        0
    };

    let _ = app.emit("download-progress", DownloadProgress {
        task_id: task_id.clone(),
        downloaded,
        total,
        speed,
        status: "completed".to_string(),
    });

    Ok(DownloadResult {
        task_id,
        success: true,
        file_path: save_path,
        error: None,
    })
}

#[tauri::command]
pub async fn download_file_chunked(
    app: AppHandle,
    task_id: String,
    url: String,
    save_path: String,
) -> Result<DownloadResult, String> {
    use futures_util::StreamExt;
    
    let path = Path::new(&save_path);
    
    if let Some(parent) = path.parent() {
        if !parent.exists() {
            fs::create_dir_all(parent)
                .map_err(|e| format!("创建目录失败: {}", e))?;
        }
    }

    let client = reqwest::Client::new();
    
    let response = client
        .get(&url)
        .send()
        .await
        .map_err(|e| format!("请求失败: {}", e))?;

    if !response.status().is_success() {
        return Err(format!("HTTP 错误: {}", response.status()));
    }

    let total = response.content_length().unwrap_or(0);

    if total > 0 && path.exists() {
        if let Ok(meta) = fs::metadata(path) {
            if meta.is_file() && meta.len() == total {
                let _ = app.emit("download-progress", DownloadProgress {
                    task_id: task_id.clone(),
                    downloaded: total,
                    total,
                    speed: 0,
                    status: "skipped".to_string(),
                });

                return Ok(DownloadResult {
                    task_id,
                    success: true,
                    file_path: save_path,
                    error: None,
                });
            }
        }
    }
    
    let _ = app.emit("download-progress", DownloadProgress {
        task_id: task_id.clone(),
        downloaded: 0,
        total,
        speed: 0,
        status: "downloading".to_string(),
    });

    let mut file = File::create(&save_path)
        .map_err(|e| format!("创建文件失败: {}", e))?;

    let mut downloaded: u64 = 0;
    let mut last_emit_time = std::time::Instant::now();
    let mut last_downloaded: u64 = 0;

    let mut stream = response.bytes_stream();

    while let Some(chunk_result) = stream.next().await {
        let chunk = chunk_result.map_err(|e| format!("下载块失败: {}", e))?;
        
        file.write_all(&chunk)
            .map_err(|e| format!("写入文件失败: {}", e))?;
        
        downloaded += chunk.len() as u64;

        let now = std::time::Instant::now();
        if now.duration_since(last_emit_time).as_millis() >= 200 {
            let elapsed = now.duration_since(last_emit_time).as_secs_f64();
            let speed = if elapsed > 0.0 {
                ((downloaded - last_downloaded) as f64 / elapsed) as u64
            } else {
                0
            };

            let _ = app.emit("download-progress", DownloadProgress {
                task_id: task_id.clone(),
                downloaded,
                total,
                speed,
                status: "downloading".to_string(),
            });

            last_emit_time = now;
            last_downloaded = downloaded;
        }
    }

    let _ = app.emit("download-progress", DownloadProgress {
        task_id: task_id.clone(),
        downloaded,
        total,
        speed: 0,
        status: "completed".to_string(),
    });

    Ok(DownloadResult {
        task_id,
        success: true,
        file_path: save_path,
        error: None,
    })
}

#[tauri::command]
pub async fn open_file_location(file_path: String) -> Result<(), String> {
    let path = Path::new(&file_path);
    
    if !path.exists() {
        return Err("文件不存在".to_string());
    }

    #[cfg(target_os = "windows")]
    {
        std::process::Command::new("C:\\Windows\\explorer.exe")
            .args(["/select,", &file_path])
            .spawn()
            .map_err(|e| format!("打开文件夹失败: {}", e))?;
    }

    #[cfg(target_os = "macos")]
    {
        std::process::Command::new("open")
            .args(["-R", &file_path])
            .spawn()
            .map_err(|e| format!("打开文件夹失败: {}", e))?;
    }

    #[cfg(target_os = "linux")]
    {
        if let Some(parent) = path.parent() {
            std::process::Command::new("xdg-open")
                .arg(parent)
                .spawn()
                .map_err(|e| format!("打开文件夹失败: {}", e))?;
        }
    }

    Ok(())
}

#[tauri::command]
pub async fn open_file(file_path: String) -> Result<(), String> {
    let path = Path::new(&file_path);
    
    if !path.exists() {
        return Err("文件不存在".to_string());
    }

    #[cfg(target_os = "windows")]
    {
        std::process::Command::new("cmd")
            .args(["/c", "start", "", &file_path])
            .spawn()
            .map_err(|e| format!("打开文件失败: {}", e))?;
    }

    #[cfg(target_os = "macos")]
    {
        std::process::Command::new("open")
            .arg(&file_path)
            .spawn()
            .map_err(|e| format!("打开文件失败: {}", e))?;
    }

    #[cfg(target_os = "linux")]
    {
        std::process::Command::new("xdg-open")
            .arg(&file_path)
            .spawn()
            .map_err(|e| format!("打开文件失败: {}", e))?;
    }

    Ok(())
}

#[tauri::command]
pub fn get_temp_dir() -> Result<String, String> {
    std::env::temp_dir()
        .to_str()
        .map(|s| s.to_string())
        .ok_or_else(|| "无法获取临时目录".to_string())
}
