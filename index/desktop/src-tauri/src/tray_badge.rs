use tauri::{AppHandle, Manager};
use std::sync::atomic::{AtomicUsize, Ordering};

static TASK_COUNT: AtomicUsize = AtomicUsize::new(0);

/// 更新托盘任务数量
#[tauri::command]
pub fn update_tray_task_count(app: AppHandle, count: usize) -> Result<(), String> {
    TASK_COUNT.store(count, Ordering::SeqCst);
    
    // 更新托盘提示文本
    if let Some(tray) = app.tray_by_id("main") {
        let tooltip = if count > 0 {
            format!("技术资源同步 - 今日 {} 项任务", count)
        } else {
            "技术资源同步 - 今日无任务".to_string()
        };
        let _ = tray.set_tooltip(Some(&tooltip));
    }
    
    Ok(())
}

/// 获取当前任务数量
#[tauri::command]
pub fn get_tray_task_count() -> usize {
    TASK_COUNT.load(Ordering::SeqCst)
}
