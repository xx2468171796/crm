mod commands;
mod downloader;
mod scanner;
mod uploader;
mod clipboard;
mod keyboard;
mod file_sync;
mod mouse_listener;
mod tray_badge;
mod window_control;

use tauri::Manager;
use tauri_plugin_global_shortcut::{Code, Modifiers, Shortcut, ShortcutState};

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    env_logger::init();

    tauri::Builder::default()
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_fs::init())
        .plugin(tauri_plugin_http::init())
        .plugin(tauri_plugin_os::init())
        .plugin(tauri_plugin_shell::init())
        .plugin(tauri_plugin_store::Builder::default().build())
        .plugin(tauri_plugin_notification::init())
        .plugin(
            tauri_plugin_global_shortcut::Builder::new()
                .with_handler(|app, shortcut, event| {
                    if event.state == ShortcutState::Pressed {
                        // Ctrl+Shift+T 切换悬浮窗
                        let toggle_shortcut = Shortcut::new(Some(Modifiers::CONTROL | Modifiers::SHIFT), Code::KeyT);
                        if shortcut == &toggle_shortcut {
                            if let Some(window) = app.get_webview_window("floating") {
                                if window.is_visible().unwrap_or(false) {
                                    let _ = window.hide();
                                } else {
                                    let _ = window.show();
                                    let _ = window.set_focus();
                                }
                            }
                        }
                    }
                })
                .build(),
        )
        .setup(|app| {
            #[cfg(desktop)]
            {
                use tauri::tray::{TrayIconBuilder, MouseButton, MouseButtonState, TrayIconEvent};
                use tauri::menu::{Menu, MenuItem};

                // 启用开发者工具（Debug 构建自动打开）
                #[cfg(debug_assertions)]
                if let Some(window) = app.get_webview_window("main") {
                    let _ = window.open_devtools();
                }

                // 注册全局快捷键 Ctrl+Shift+T
                use tauri_plugin_global_shortcut::GlobalShortcutExt;
                let toggle_shortcut = Shortcut::new(Some(Modifiers::CONTROL | Modifiers::SHIFT), Code::KeyT);
                if let Err(e) = app.global_shortcut().register(toggle_shortcut) {
                    log::warn!("注册快捷键失败: {:?}", e);
                }

                // 启动全局鼠标监听
                mouse_listener::start_mouse_listener();

                let show_floating = MenuItem::with_id(app, "show_floating", "显示悬浮窗", true, None::<&str>)?;
                let show = MenuItem::with_id(app, "show", "显示主窗口", true, None::<&str>)?;
                let devtools = MenuItem::with_id(app, "devtools", "开发者工具 (F12)", true, None::<&str>)?;
                let quit = MenuItem::with_id(app, "quit", "退出", true, None::<&str>)?;
                let menu = Menu::with_items(app, &[&show_floating, &show, &devtools, &quit])?;

                let _tray = TrayIconBuilder::with_id("main")
                    .icon(app.default_window_icon().unwrap().clone())
                    .menu(&menu)
                    .show_menu_on_left_click(false)
                    .on_menu_event(|app, event| match event.id.as_ref() {
                        "show_floating" => {
                            if let Some(window) = app.get_webview_window("floating") {
                                let _ = window.show();
                                let _ = window.set_focus();
                            }
                        }
                        "show" => {
                            if let Some(window) = app.get_webview_window("main") {
                                let _ = window.show();
                                let _ = window.set_focus();
                            }
                        }
                        "devtools" => {
                            if let Some(window) = app.get_webview_window("main") {
                                let _ = window.open_devtools();
                            }
                        }
                        "quit" => {
                            app.exit(0);
                        }
                        _ => {}
                    })
                    .on_tray_icon_event(|tray, event| {
                        if let TrayIconEvent::Click { button: MouseButton::Left, button_state: MouseButtonState::Up, .. } = event {
                            if let Some(window) = tray.app_handle().get_webview_window("main") {
                                let _ = window.show();
                                let _ = window.set_focus();
                            }
                        }
                    })
                    .build(app)?;
            }
            Ok(())
        })
        .on_window_event(|window, event| {
            // 拦截主窗口关闭事件，隐藏而不是销毁
            if window.label() == "main" {
                if let tauri::WindowEvent::CloseRequested { api, .. } = event {
                    // 阻止默认关闭行为
                    api.prevent_close();
                    // 隐藏窗口
                    let _ = window.hide();
                }
            }
        })
        .invoke_handler(tauri::generate_handler![
            commands::scan_root_directory,
            commands::list_dir_entries,
            commands::scan_folder_recursive,
            commands::get_local_files,
            commands::calculate_file_hash,
            commands::get_file_metadata,
            commands::read_file_chunk,
            commands::write_file_chunk,
            commands::ensure_directory,
            commands::get_mime_type,
            downloader::download_file,
            downloader::download_file_chunked,
            downloader::open_file_location,
            downloader::open_file,
            downloader::get_temp_dir,
            uploader::upload_file_to_url,
            uploader::upload_file_part,
            clipboard::copy_files_to_clipboard,
            clipboard::copy_text_to_clipboard,
            clipboard::get_clipboard_text,
            clipboard::clipboard_has_files,
            keyboard::simulate_paste,
            keyboard::simulate_enter,
            keyboard::simulate_type_text,
            keyboard::simulate_paste_and_send,
            file_sync::create_project_folders,
            file_sync::get_sync_rule,
            file_sync::rename_model_file,
            file_sync::should_sync_file,
            file_sync::get_sync_status,
            file_sync::open_project_folder,
            file_sync::project_folder_exists,
            mouse_listener::save_mouse_position,
            mouse_listener::get_saved_position,
            mouse_listener::click_saved_position,
            tray_badge::update_tray_task_count,
            tray_badge::get_tray_task_count,
            window_control::show_main_window,
            window_control::hide_floating_window,
            window_control::minimize_floating_window,
            window_control::set_floating_always_on_top,
            window_control::is_floating_always_on_top,
            window_control::start_floating_drag,
            window_control::set_floating_size,
            window_control::get_floating_size,
        ])
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
