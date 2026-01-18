use enigo::{Direction, Enigo, Key, Keyboard, Settings};
use std::thread;
use std::time::Duration;

/// 模拟粘贴操作 (Ctrl+V)
#[tauri::command]
pub fn simulate_paste() -> Result<(), String> {
    let mut enigo = Enigo::new(&Settings::default())
        .map_err(|e| format!("初始化键盘模拟失败: {}", e))?;
    
    // 短暂延迟确保焦点切换完成
    thread::sleep(Duration::from_millis(100));
    
    // Ctrl+V
    enigo.key(Key::Control, Direction::Press)
        .map_err(|e| format!("按下 Ctrl 失败: {}", e))?;
    enigo.key(Key::Unicode('v'), Direction::Click)
        .map_err(|e| format!("按下 V 失败: {}", e))?;
    enigo.key(Key::Control, Direction::Release)
        .map_err(|e| format!("释放 Ctrl 失败: {}", e))?;
    
    Ok(())
}

/// 模拟回车键
#[tauri::command]
pub fn simulate_enter() -> Result<(), String> {
    let mut enigo = Enigo::new(&Settings::default())
        .map_err(|e| format!("初始化键盘模拟失败: {}", e))?;
    
    thread::sleep(Duration::from_millis(50));
    
    enigo.key(Key::Return, Direction::Click)
        .map_err(|e| format!("按下 Enter 失败: {}", e))?;
    
    Ok(())
}

/// 模拟发送文本（输入后回车）
#[tauri::command]
pub fn simulate_type_text(text: String) -> Result<(), String> {
    let mut enigo = Enigo::new(&Settings::default())
        .map_err(|e| format!("初始化键盘模拟失败: {}", e))?;
    
    thread::sleep(Duration::from_millis(100));
    
    enigo.text(&text)
        .map_err(|e| format!("输入文本失败: {}", e))?;
    
    Ok(())
}

/// 模拟粘贴并发送（Ctrl+V 然后 Enter）
#[tauri::command]
pub fn simulate_paste_and_send() -> Result<(), String> {
    simulate_paste()?;
    thread::sleep(Duration::from_millis(200));
    simulate_enter()?;
    Ok(())
}
