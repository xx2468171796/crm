use regex::Regex;
use std::fs;
use std::path::Path;
use crate::commands::{GroupFolder, LocalFile};

pub fn parse_group_folder(folder_name: &str, path: &Path) -> Option<GroupFolder> {
    let re = Regex::new(r"^(Q\d{10,})(?:_(.+))?$").ok()?;
    
    let captures = re.captures(folder_name)?;
    let group_code = captures.get(1)?.as_str().to_string();
    let group_name = captures
        .get(2)
        .map(|m| m.as_str().to_string())
        .unwrap_or_else(|| "".to_string());
    
    let has_works = path.join("作品文件").exists();
    let has_models = path.join("模型文件").exists();
    let has_customer = path.join("客户文件").exists();
    
    Some(GroupFolder {
        group_code,
        group_name,
        path: path.to_string_lossy().to_string(),
        has_works,
        has_models,
        has_customer,
    })
}

pub fn collect_files(base_path: &Path, current_path: &Path) -> Result<Vec<LocalFile>, String> {
    let mut files = Vec::new();
    
    if !current_path.exists() || !current_path.is_dir() {
        return Ok(files);
    }
    
    let entries = fs::read_dir(current_path)
        .map_err(|e| format!("无法读取目录: {}", e))?;
    
    for entry in entries {
        let entry = match entry {
            Ok(e) => e,
            Err(_) => continue,
        };
        
        let entry_path = entry.path();
        let metadata = match entry.metadata() {
            Ok(m) => m,
            Err(_) => continue,
        };
        
        let filename = match entry.file_name().to_str() {
            Some(name) => name.to_string(),
            None => continue,
        };
        
        if filename.starts_with('.') {
            continue;
        }
        
        let rel_path = entry_path
            .strip_prefix(base_path)
            .map(|p| p.to_string_lossy().to_string())
            .unwrap_or_else(|_| filename.clone());
        
        let modified_at = metadata.modified()
            .map(|t| t.duration_since(std::time::UNIX_EPOCH).unwrap_or_default().as_secs())
            .unwrap_or(0);
        
        if metadata.is_file() {
            files.push(LocalFile {
                rel_path,
                filename,
                size: metadata.len(),
                modified_at,
                is_dir: false,
            });
        } else if metadata.is_dir() {
            files.push(LocalFile {
                rel_path: rel_path.clone(),
                filename: filename.clone(),
                size: 0,
                modified_at,
                is_dir: true,
            });
            
            let sub_files = collect_files(base_path, &entry_path)?;
            files.extend(sub_files);
        }
    }
    
    Ok(files)
}
