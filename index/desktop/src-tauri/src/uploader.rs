use serde::{Deserialize, Serialize};
use std::fs::File;
use std::io::{Read, Seek, SeekFrom};
use std::path::Path;

// 上传文件到指定 URL（普通上传）
#[tauri::command]
pub async fn upload_file_to_url(
    url: String,
    file_path: String,
    token: Option<String>,
    field_name: Option<String>,
) -> Result<String, String> {
    let path = Path::new(&file_path);
    if !path.exists() {
        return Err(format!("文件不存在: {}", file_path));
    }

    let file_name = path.file_name()
        .and_then(|n| n.to_str())
        .unwrap_or("file")
        .to_string();

    let file_bytes = std::fs::read(&file_path)
        .map_err(|e| format!("读取文件失败: {}", e))?;

    let field = field_name.unwrap_or_else(|| "file".to_string());
    
    let part = reqwest::multipart::Part::bytes(file_bytes)
        .file_name(file_name)
        .mime_str("application/octet-stream")
        .map_err(|e| format!("设置 MIME 类型失败: {}", e))?;

    let form = reqwest::multipart::Form::new().part(field, part);

    let client = reqwest::Client::new();
    let mut request = client.post(&url).multipart(form);

    if let Some(t) = token {
        request = request.header("Authorization", format!("Bearer {}", t));
    }

    let response = request
        .send()
        .await
        .map_err(|e| format!("上传请求失败: {}", e))?;

    if !response.status().is_success() {
        let status = response.status();
        let body = response.text().await.unwrap_or_default();
        return Err(format!("上传失败 HTTP {}: {}", status, body));
    }

    let body = response.text().await
        .map_err(|e| format!("读取响应失败: {}", e))?;

    Ok(body)
}

// 上传文件分片（S3 分片上传）
#[tauri::command]
pub fn upload_file_part(
    url: String,
    file_path: String,
    part_number: u32,
    part_size: u64,
    total_size: u64,
    token: Option<String>,
) -> Result<String, String> {
    let path = Path::new(&file_path);
    if !path.exists() {
        return Err(format!("文件不存在: {}", file_path));
    }

    let mut file = File::open(&file_path)
        .map_err(|e| format!("打开文件失败: {}", e))?;

    // 计算分片偏移
    let offset = (part_number as u64 - 1) * part_size;
    let remaining = total_size.saturating_sub(offset);
    let chunk_size = remaining.min(part_size) as usize;

    // 跳转到分片位置
    file.seek(SeekFrom::Start(offset))
        .map_err(|e| format!("文件定位失败: {}", e))?;

    // 读取分片数据
    let mut buffer = vec![0u8; chunk_size];
    file.read_exact(&mut buffer)
        .map_err(|e| format!("读取分片 {} 失败: {}", part_number, e))?;

    // 发送分片
    let client = reqwest::blocking::Client::new();
    let mut request = client.put(&url)
        .header("Content-Type", "application/octet-stream")
        .body(buffer);

    if let Some(t) = token {
        request = request.header("Authorization", format!("Bearer {}", t));
    }

    let resp = request.send()
        .map_err(|e| format!("上传分片 {} 失败: {}", part_number, e))?;

    if !resp.status().is_success() {
        let status = resp.status();
        let body = resp.text().unwrap_or_default();
        return Err(format!("上传分片 {} 失败: HTTP {} - {}", part_number, status, body));
    }

    // 获取 ETag（S3 分片上传需要）
    let etag = resp
        .headers()
        .get("etag")
        .and_then(|v| v.to_str().ok())
        .map(|s| s.to_string())
        .ok_or_else(|| "响应缺少 ETag".to_string())?;

    Ok(etag)
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct UploadPart {
    pub part_number: u32,
    pub etag: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct UploadSession {
    pub upload_id: String,
    pub storage_key: String,
    pub file_path: String,
    pub file_size: u64,
    pub part_size: u64,
    pub total_parts: u32,
    pub uploaded_parts: Vec<UploadPart>,
    pub status: UploadStatus,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub enum UploadStatus {
    Pending,
    Uploading,
    Paused,
    Completed,
    Failed,
}

impl UploadSession {
    pub fn new(
        upload_id: String,
        storage_key: String,
        file_path: String,
        file_size: u64,
        part_size: u64,
    ) -> Self {
        let total_parts = ((file_size as f64) / (part_size as f64)).ceil() as u32;
        
        Self {
            upload_id,
            storage_key,
            file_path,
            file_size,
            part_size,
            total_parts,
            uploaded_parts: Vec::new(),
            status: UploadStatus::Pending,
        }
    }
    
    pub fn progress(&self) -> f64 {
        if self.total_parts == 0 {
            return 0.0;
        }
        (self.uploaded_parts.len() as f64) / (self.total_parts as f64) * 100.0
    }
    
    pub fn next_part_number(&self) -> Option<u32> {
        let uploaded: std::collections::HashSet<u32> = self.uploaded_parts
            .iter()
            .map(|p| p.part_number)
            .collect();
        
        for i in 1..=self.total_parts {
            if !uploaded.contains(&i) {
                return Some(i);
            }
        }
        
        None
    }
    
    pub fn add_part(&mut self, part_number: u32, etag: String) {
        if !self.uploaded_parts.iter().any(|p| p.part_number == part_number) {
            self.uploaded_parts.push(UploadPart { part_number, etag });
        }
        
        if self.uploaded_parts.len() == self.total_parts as usize {
            self.status = UploadStatus::Completed;
        }
    }
    
    pub fn get_sorted_parts(&self) -> Vec<UploadPart> {
        let mut parts = self.uploaded_parts.clone();
        parts.sort_by_key(|p| p.part_number);
        parts
    }
}
