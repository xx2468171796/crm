use tauri::{AppHandle, Manager, LogicalSize};

/// 显示主窗口
#[tauri::command]
pub fn show_main_window(app: AppHandle) -> Result<(), String> {
    log::info!("[window_control] show_main_window 被调用");
    if let Some(window) = app.get_webview_window("main") {
        log::info!("[window_control] 找到主窗口，正在显示...");
        window.show().map_err(|e| {
            log::error!("[window_control] 显示失败: {}", e);
            e.to_string()
        })?;
        window.unminimize().map_err(|e| {
            log::error!("[window_control] 取消最小化失败: {}", e);
            e.to_string()
        })?;
        window.set_focus().map_err(|e| {
            log::error!("[window_control] 聚焦失败: {}", e);
            e.to_string()
        })?;
        log::info!("[window_control] 显示成功");
    } else {
        log::warn!("[window_control] 未找到主窗口");
        return Err("主窗口未找到".to_string());
    }
    Ok(())
}

/// 隐藏悬浮窗
#[tauri::command]
pub fn hide_floating_window(app: AppHandle) -> Result<(), String> {
    log::info!("[window_control] hide_floating_window 被调用");
    if let Some(window) = app.get_webview_window("floating") {
        log::info!("[window_control] 找到悬浮窗，正在隐藏...");
        window.hide().map_err(|e| {
            log::error!("[window_control] 隐藏失败: {}", e);
            e.to_string()
        })?;
        log::info!("[window_control] 隐藏成功");
    } else {
        log::warn!("[window_control] 未找到悬浮窗");
    }
    Ok(())
}

/// 最小化悬浮窗
#[tauri::command]
pub fn minimize_floating_window(app: AppHandle) -> Result<(), String> {
    log::info!("[window_control] minimize_floating_window 被调用");
    if let Some(window) = app.get_webview_window("floating") {
        log::info!("[window_control] 找到悬浮窗，正在最小化...");
        window.minimize().map_err(|e| {
            log::error!("[window_control] 最小化失败: {}", e);
            e.to_string()
        })?;
        log::info!("[window_control] 最小化成功");
    } else {
        log::warn!("[window_control] 未找到悬浮窗");
    }
    Ok(())
}

/// 设置悬浮窗置顶状态
#[tauri::command]
pub fn set_floating_always_on_top(app: AppHandle, on_top: bool) -> Result<(), String> {
    log::info!("[window_control] set_floating_always_on_top 被调用, on_top: {}", on_top);
    if let Some(window) = app.get_webview_window("floating") {
        log::info!("[window_control] 找到悬浮窗，正在设置置顶...");
        window.set_always_on_top(on_top).map_err(|e| {
            log::error!("[window_control] 设置置顶失败: {}", e);
            e.to_string()
        })?;
        log::info!("[window_control] 设置置顶成功");
    } else {
        log::warn!("[window_control] 未找到悬浮窗");
    }
    Ok(())
}

/// 获取悬浮窗置顶状态
#[tauri::command]
pub fn is_floating_always_on_top(app: AppHandle) -> Result<bool, String> {
    log::info!("[window_control] is_floating_always_on_top 被调用");
    if let Some(window) = app.get_webview_window("floating") {
        let result = window.is_always_on_top().map_err(|e| {
            log::error!("[window_control] 获取置顶状态失败: {}", e);
            e.to_string()
        })?;
        log::info!("[window_control] 置顶状态: {}", result);
        Ok(result)
    } else {
        log::warn!("[window_control] 未找到悬浮窗");
        Ok(false)
    }
}

/// 开始拖拽悬浮窗
#[tauri::command]
pub fn start_floating_drag(app: AppHandle) -> Result<(), String> {
    log::info!("[window_control] start_floating_drag 被调用");
    if let Some(window) = app.get_webview_window("floating") {
        log::info!("[window_control] 找到悬浮窗，正在开始拖拽...");
        window.start_dragging().map_err(|e| {
            log::error!("[window_control] 拖拽失败: {}", e);
            e.to_string()
        })?;
        log::info!("[window_control] 拖拽成功");
    } else {
        log::warn!("[window_control] 未找到悬浮窗");
    }
    Ok(())
}

/// 设置悬浮窗大小
#[tauri::command]
pub fn set_floating_size(app: AppHandle, width: f64, height: f64) -> Result<(), String> {
    log::info!("[window_control] set_floating_size 被调用, width: {}, height: {}", width, height);
    if let Some(window) = app.get_webview_window("floating") {
        log::info!("[window_control] 找到悬浮窗，正在设置大小...");
        window.set_size(LogicalSize::new(width, height)).map_err(|e| {
            log::error!("[window_control] 设置大小失败: {}", e);
            e.to_string()
        })?;
        log::info!("[window_control] 设置大小成功");
    } else {
        log::warn!("[window_control] 未找到悬浮窗");
    }
    Ok(())
}

/// 获取悬浮窗大小
#[tauri::command]
pub fn get_floating_size(app: AppHandle) -> Result<(f64, f64), String> {
    log::info!("[window_control] get_floating_size 被调用");
    if let Some(window) = app.get_webview_window("floating") {
        let size = window.inner_size().map_err(|e| {
            log::error!("[window_control] 获取大小失败: {}", e);
            e.to_string()
        })?;
        log::info!("[window_control] 大小: {}x{}", size.width, size.height);
        Ok((size.width as f64, size.height as f64))
    } else {
        log::warn!("[window_control] 未找到悬浮窗");
        Ok((320.0, 480.0))
    }
}
