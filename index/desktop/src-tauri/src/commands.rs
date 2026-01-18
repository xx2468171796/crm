use serde::{Deserialize, Serialize};
use std::fs;
use std::path::Path;
use crate::scanner;

#[derive(Debug, Serialize, Deserialize)]
pub struct GroupFolder {
    pub group_code: String,
    pub group_name: String,
    pub path: String,
    pub has_works: bool,
    pub has_models: bool,
    pub has_customer: bool,
}

#[derive(Debug, Serialize, Deserialize)]
pub struct LocalFile {
    pub rel_path: String,
    pub filename: String,
    pub size: u64,
    pub modified_at: u64,
    pub is_dir: bool,
}

#[derive(Debug, Serialize, Deserialize)]
pub struct FileMetadata {
    pub path: String,
    pub size: u64,
    pub modified_at: u64,
    pub created_at: u64,
    pub is_file: bool,
    pub is_dir: bool,
}

#[derive(Debug, Serialize, Deserialize)]
pub struct DirEntryInfo {
    pub name: String,
    pub path: String,
    pub is_file: bool,
    pub is_dir: bool,
}

#[tauri::command]
pub async fn scan_root_directory(root_path: String) -> Result<Vec<GroupFolder>, String> {
    let path = Path::new(&root_path);
    
    if !path.exists() {
        return Err("目录不存在".to_string());
    }
    
    if !path.is_dir() {
        return Err("路径不是目录".to_string());
    }

    let mut groups = Vec::new();
    
    let entries = fs::read_dir(path).map_err(|e| format!("无法读取目录: {}", e))?;
    
    for entry in entries {
        let entry = match entry {
            Ok(e) => e,
            Err(_) => continue,
        };
        
        let entry_path = entry.path();
        if !entry_path.is_dir() {
            continue;
        }
        
        let folder_name = match entry.file_name().to_str() {
            Some(name) => name.to_string(),
            None => continue,
        };
        
        if let Some(group) = scanner::parse_group_folder(&folder_name, &entry_path) {
            groups.push(group);
        }
    }
    
    groups.sort_by(|a, b| a.group_code.cmp(&b.group_code));
    
    Ok(groups)
}

#[tauri::command]
pub async fn list_dir_entries(dir_path: String) -> Result<Vec<DirEntryInfo>, String> {
    let path = Path::new(&dir_path);

    if !path.exists() {
        return Ok(Vec::new());
    }

    if !path.is_dir() {
        return Err("路径不是目录".to_string());
    }

    let entries = fs::read_dir(path).map_err(|e| format!("无法读取目录: {}", e))?;
    let mut items = Vec::new();

    for entry in entries {
        let entry = entry.map_err(|e| format!("无法读取目录项: {}", e))?;
        let entry_path = entry.path();
        let name = entry
            .file_name()
            .to_string_lossy()
            .to_string();
        let metadata = entry
            .metadata()
            .map_err(|e| format!("无法读取目录项元数据: {}", e))?;

        items.push(DirEntryInfo {
            name,
            path: entry_path.to_string_lossy().to_string(),
            is_file: metadata.is_file(),
            is_dir: metadata.is_dir(),
        });
    }

    Ok(items)
}

#[derive(Debug, Serialize)]
pub struct FolderFileInfo {
    pub name: String,
    pub relative_path: String,
    pub absolute_path: String,
    pub size: u64,
}

#[tauri::command]
pub async fn scan_folder_recursive(folder_path: String) -> Result<Vec<FolderFileInfo>, String> {
    let root = Path::new(&folder_path);
    
    if !root.exists() {
        return Err("文件夹不存在".to_string());
    }
    
    if !root.is_dir() {
        return Err("路径不是文件夹".to_string());
    }
    
    let mut files = Vec::new();
    scan_folder_inner(root, root, &mut files)?;
    
    Ok(files)
}

fn scan_folder_inner(root: &Path, current: &Path, files: &mut Vec<FolderFileInfo>) -> Result<(), String> {
    let entries = fs::read_dir(current).map_err(|e| format!("无法读取目录: {}", e))?;
    
    for entry in entries {
        let entry = entry.map_err(|e| format!("无法读取目录项: {}", e))?;
        let path = entry.path();
        
        if path.is_dir() {
            scan_folder_inner(root, &path, files)?;
        } else if path.is_file() {
            let metadata = path.metadata().map_err(|e| format!("无法读取文件元数据: {}", e))?;
            let relative = path.strip_prefix(root).map_err(|_| "无法计算相对路径")?;
            
            files.push(FolderFileInfo {
                name: path.file_name().unwrap_or_default().to_string_lossy().to_string(),
                relative_path: relative.to_string_lossy().to_string().replace('\\', "/"),
                absolute_path: path.to_string_lossy().to_string(),
                size: metadata.len(),
            });
        }
    }
    
    Ok(())
}

#[tauri::command]
pub async fn get_local_files(
    root_path: String,
    group_code: String,
    asset_type: String,
) -> Result<Vec<LocalFile>, String> {
    let asset_dir = match asset_type.as_str() {
        "works" => "作品文件",
        "models" => "模型文件",
        "customer" => "客户文件",
        _ => return Err("无效的资源类型".to_string()),
    };
    
    let entries = fs::read_dir(&root_path).map_err(|e| format!("无法读取目录: {}", e))?;
    
    let mut target_path: Option<std::path::PathBuf> = None;
    
    for entry in entries {
        let entry = match entry {
            Ok(e) => e,
            Err(_) => continue,
        };
        
        let folder_name = match entry.file_name().to_str() {
            Some(name) => name.to_string(),
            None => continue,
        };
        
        if folder_name.starts_with(&format!("{}_", group_code)) {
            let asset_path = entry.path().join(asset_dir);
            if asset_path.exists() {
                target_path = Some(asset_path);
                break;
            }
        }
    }
    
    let target_path = match target_path {
        Some(p) => p,
        None => return Ok(Vec::new()),
    };
    
    let files = scanner::collect_files(&target_path, &target_path)?;
    
    Ok(files)
}

#[tauri::command]
pub async fn calculate_file_hash(file_path: String) -> Result<String, String> {
    use sha2::{Sha256, Digest};
    use std::io::Read;
    
    let mut file = fs::File::open(&file_path)
        .map_err(|e| format!("无法打开文件: {}", e))?;
    
    let mut hasher = Sha256::new();
    let mut buffer = [0u8; 8192];
    
    loop {
        let bytes_read = file.read(&mut buffer)
            .map_err(|e| format!("读取文件失败: {}", e))?;
        if bytes_read == 0 {
            break;
        }
        hasher.update(&buffer[..bytes_read]);
    }
    
    let result = hasher.finalize();
    Ok(hex::encode(result))
}

#[tauri::command]
pub async fn get_file_metadata(file_path: String) -> Result<FileMetadata, String> {
    let path = Path::new(&file_path);
    
    if !path.exists() {
        return Err("文件不存在".to_string());
    }
    
    let metadata = fs::metadata(path)
        .map_err(|e| format!("无法获取文件元数据: {}", e))?;
    
    let modified_at = metadata.modified()
        .map(|t| t.duration_since(std::time::UNIX_EPOCH).unwrap_or_default().as_secs())
        .unwrap_or(0);
    
    let created_at = metadata.created()
        .map(|t| t.duration_since(std::time::UNIX_EPOCH).unwrap_or_default().as_secs())
        .unwrap_or(0);
    
    Ok(FileMetadata {
        path: file_path,
        size: metadata.len(),
        modified_at,
        created_at,
        is_file: metadata.is_file(),
        is_dir: metadata.is_dir(),
    })
}

#[tauri::command]
pub async fn read_file_chunk(
    file_path: String,
    offset: u64,
    length: u64,
) -> Result<Vec<u8>, String> {
    use std::io::{Read, Seek, SeekFrom};
    
    let mut file = fs::File::open(&file_path)
        .map_err(|e| format!("无法打开文件: {}", e))?;
    
    file.seek(SeekFrom::Start(offset))
        .map_err(|e| format!("文件定位失败: {}", e))?;
    
    let mut buffer = vec![0u8; length as usize];
    let bytes_read = file.read(&mut buffer)
        .map_err(|e| format!("读取文件失败: {}", e))?;
    
    buffer.truncate(bytes_read);
    Ok(buffer)
}

#[tauri::command]
pub async fn write_file_chunk(
    file_path: String,
    data: Vec<u8>,
    append: bool,
) -> Result<u64, String> {
    use std::io::Write;
    
    let path = Path::new(&file_path);
    
    if let Some(parent) = path.parent() {
        if !parent.exists() {
            fs::create_dir_all(parent)
                .map_err(|e| format!("创建目录失败: {}", e))?;
        }
    }
    
    let mut file = if append {
        fs::OpenOptions::new()
            .create(true)
            .append(true)
            .open(&file_path)
            .map_err(|e| format!("打开文件失败: {}", e))?
    } else {
        fs::File::create(&file_path)
            .map_err(|e| format!("创建文件失败: {}", e))?
    };
    
    let bytes_written = file.write(&data)
        .map_err(|e| format!("写入文件失败: {}", e))?;
    
    Ok(bytes_written as u64)
}

#[tauri::command]
pub async fn ensure_directory(dir_path: String) -> Result<(), String> {
    let path = Path::new(&dir_path);
    
    if !path.exists() {
        fs::create_dir_all(path)
            .map_err(|e| format!("创建目录失败: {}", e))?;
    }
    
    Ok(())
}

#[tauri::command]
pub async fn get_mime_type(file_path: String) -> Result<String, String> {
    let path = Path::new(&file_path);
    
    let extension = path.extension()
        .and_then(|e| e.to_str())
        .unwrap_or("");
    
    let mime = match extension.to_lowercase().as_str() {
        "jpg" | "jpeg" => "image/jpeg",
        "png" => "image/png",
        "gif" => "image/gif",
        "webp" => "image/webp",
        "svg" => "image/svg+xml",
        "pdf" => "application/pdf",
        "doc" => "application/msword",
        "docx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        "xls" => "application/vnd.ms-excel",
        "xlsx" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        "ppt" => "application/vnd.ms-powerpoint",
        "pptx" => "application/vnd.openxmlformats-officedocument.presentationml.presentation",
        "zip" => "application/zip",
        "rar" => "application/x-rar-compressed",
        "7z" => "application/x-7z-compressed",
        "mp3" => "audio/mpeg",
        "wav" => "audio/wav",
        "mp4" => "video/mp4",
        "avi" => "video/x-msvideo",
        "mov" => "video/quicktime",
        "psd" => "image/vnd.adobe.photoshop",
        "ai" => "application/postscript",
        "eps" => "application/postscript",
        "obj" => "model/obj",
        "fbx" => "application/octet-stream",
        "max" => "application/octet-stream",
        "blend" => "application/x-blender",
        "c4d" => "application/octet-stream",
        "ma" | "mb" => "application/octet-stream",
        "txt" => "text/plain",
        "json" => "application/json",
        "xml" => "application/xml",
        "html" => "text/html",
        "css" => "text/css",
        "js" => "application/javascript",
        _ => "application/octet-stream",
    };
    
    Ok(mime.to_string())
}
