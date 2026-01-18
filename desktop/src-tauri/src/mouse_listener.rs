use rdev::{listen, Event, EventType, Button};
use std::sync::{Arc, Mutex};
use std::thread;

/// 保存的鼠标位置
#[derive(Clone, Default)]
pub struct MousePosition {
    pub x: f64,
    pub y: f64,
}

lazy_static::lazy_static! {
    static ref SAVED_POSITION: Arc<Mutex<Option<MousePosition>>> = Arc::new(Mutex::new(None));
    static ref LAST_CLICK_POSITION: Arc<Mutex<MousePosition>> = Arc::new(Mutex::new(MousePosition::default()));
}

/// 保存当前鼠标点击位置
#[tauri::command]
pub fn save_mouse_position() -> Result<(f64, f64), String> {
    let pos = LAST_CLICK_POSITION.lock().map_err(|e| e.to_string())?;
    let mut saved = SAVED_POSITION.lock().map_err(|e| e.to_string())?;
    *saved = Some(pos.clone());
    Ok((pos.x, pos.y))
}

/// 获取保存的鼠标位置
#[tauri::command]
pub fn get_saved_position() -> Result<Option<(f64, f64)>, String> {
    let saved = SAVED_POSITION.lock().map_err(|e| e.to_string())?;
    Ok(saved.as_ref().map(|p| (p.x, p.y)))
}

/// 点击保存的位置
#[tauri::command]
pub fn click_saved_position() -> Result<(), String> {
    use enigo::{Enigo, Mouse, Settings, Coordinate, Button as EnigoButton};
    
    let saved = SAVED_POSITION.lock().map_err(|e| e.to_string())?;
    let pos = saved.as_ref().ok_or("没有保存的位置")?;
    
    let mut enigo = Enigo::new(&Settings::default())
        .map_err(|e| format!("初始化鼠标模拟失败: {}", e))?;
    
    // 移动到保存的位置并点击
    enigo.move_mouse(pos.x as i32, pos.y as i32, Coordinate::Abs)
        .map_err(|e| format!("移动鼠标失败: {}", e))?;
    
    std::thread::sleep(std::time::Duration::from_millis(50));
    
    enigo.button(EnigoButton::Left, enigo::Direction::Click)
        .map_err(|e| format!("点击失败: {}", e))?;
    
    Ok(())
}

/// 启动全局鼠标监听（在后台线程运行）
pub fn start_mouse_listener() {
    thread::spawn(|| {
        if let Err(e) = listen(|event: Event| {
            if let EventType::ButtonPress(Button::Left) = event.event_type {
                // 记录左键点击位置
                if let Some((x, y)) = get_cursor_position() {
                    if let Ok(mut pos) = LAST_CLICK_POSITION.lock() {
                        pos.x = x;
                        pos.y = y;
                    }
                }
            }
        }) {
            log::error!("鼠标监听错误: {:?}", e);
        }
    });
}

/// 获取当前鼠标位置
fn get_cursor_position() -> Option<(f64, f64)> {
    // Windows: 使用 Windows API
    #[cfg(target_os = "windows")]
    {
        use std::mem::MaybeUninit;
        
        #[repr(C)]
        struct POINT {
            x: i32,
            y: i32,
        }
        
        extern "system" {
            fn GetCursorPos(lpPoint: *mut POINT) -> i32;
        }
        
        unsafe {
            let mut point = MaybeUninit::<POINT>::uninit();
            if GetCursorPos(point.as_mut_ptr()) != 0 {
                let point = point.assume_init();
                return Some((point.x as f64, point.y as f64));
            }
        }
        None
    }
    
    #[cfg(not(target_os = "windows"))]
    {
        None
    }
}
