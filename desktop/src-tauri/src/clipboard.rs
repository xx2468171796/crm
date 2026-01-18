use clipboard_rs::{Clipboard, ClipboardContext, ContentFormat};
use std::path::Path;

/// 复制文件到剪贴板
#[tauri::command]
pub fn copy_files_to_clipboard(paths: Vec<String>) -> Result<(), String> {
    let ctx = ClipboardContext::new().map_err(|e| format!("剪贴板初始化失败: {}", e))?;
    
    // 验证文件存在
    for path in &paths {
        if !Path::new(path).exists() {
            return Err(format!("文件不存在: {}", path));
        }
    }
    
    // 设置文件到剪贴板
    ctx.set_files(paths)
        .map_err(|e| format!("复制文件失败: {}", e))?;
    
    Ok(())
}

/// 复制文本到剪贴板
#[tauri::command]
pub fn copy_text_to_clipboard(text: String) -> Result<(), String> {
    let ctx = ClipboardContext::new().map_err(|e| format!("剪贴板初始化失败: {}", e))?;
    
    ctx.set_text(text)
        .map_err(|e| format!("复制文本失败: {}", e))?;
    
    Ok(())
}

/// 获取剪贴板文本
#[tauri::command]
pub fn get_clipboard_text() -> Result<String, String> {
    let ctx = ClipboardContext::new().map_err(|e| format!("剪贴板初始化失败: {}", e))?;
    
    ctx.get_text()
        .map_err(|e| format!("获取剪贴板内容失败: {}", e))
}

/// 检查剪贴板是否有文件
#[tauri::command]
pub fn clipboard_has_files() -> Result<bool, String> {
    let ctx = ClipboardContext::new().map_err(|e| format!("剪贴板初始化失败: {}", e))?;
    
    Ok(ctx.has(ContentFormat::Files))
}
