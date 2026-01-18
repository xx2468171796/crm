/**
 * 文件夹上传工具类（三端共用）
 * 
 * 支持：桌面端、Web端、客户门户
 * 统一调用后端API，禁止硬编码
 */

const FolderUploader = (function() {
    'use strict';
    
    // API端点配置（统一管理，禁止硬编码）
    const API_ENDPOINTS = {
        uploadInit: '/api/upload_init.php',
        uploadFolderInit: '/api/upload_folder_init.php',
        uploadPartUrl: '/api/upload_part_url.php',
        uploadPart: '/api/upload_part.php',
        uploadComplete: '/api/upload_complete.php',
    };
    
    // 分片大小：50MB（与后端保持一致）
    const PART_SIZE = 50 * 1024 * 1024;
    
    /**
     * 递归扫描文件夹，获取所有文件及其相对路径
     * @param {DataTransferItem|FileSystemEntry} entry 
     * @param {string} basePath 
     * @returns {Promise<Array<{file: File, relPath: string}>>}
     */
    async function scanFolder(entry, basePath = '') {
        const results = [];
        
        if (entry.isFile) {
            const file = await new Promise((resolve, reject) => {
                entry.file(resolve, reject);
            });
            results.push({
                file: file,
                relPath: basePath + file.name,
            });
        } else if (entry.isDirectory) {
            const reader = entry.createReader();
            const entries = await new Promise((resolve, reject) => {
                const allEntries = [];
                const readEntries = () => {
                    reader.readEntries((batch) => {
                        if (batch.length === 0) {
                            resolve(allEntries);
                        } else {
                            allEntries.push(...batch);
                            readEntries();
                        }
                    }, reject);
                };
                readEntries();
            });
            
            for (const childEntry of entries) {
                const childResults = await scanFolder(childEntry, basePath + entry.name + '/');
                results.push(...childResults);
            }
        }
        
        return results;
    }
    
    /**
     * 从拖拽事件中提取文件列表
     * @param {DragEvent} event 
     * @returns {Promise<Array<{file: File, relPath: string}>>}
     */
    async function extractFilesFromDrop(event) {
        const items = event.dataTransfer.items;
        const results = [];
        
        for (let i = 0; i < items.length; i++) {
            const item = items[i];
            if (item.kind === 'file') {
                const entry = item.webkitGetAsEntry ? item.webkitGetAsEntry() : null;
                if (entry) {
                    const files = await scanFolder(entry);
                    results.push(...files);
                } else {
                    const file = item.getAsFile();
                    if (file) {
                        results.push({ file: file, relPath: file.name });
                    }
                }
            }
        }
        
        return results;
    }
    
    /**
     * 从input[type=file]中提取文件列表
     * @param {HTMLInputElement} input 
     * @returns {Array<{file: File, relPath: string}>}
     */
    function extractFilesFromInput(input) {
        const files = input.files;
        const results = [];
        
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            // webkitRelativePath 包含完整的相对路径
            const relPath = file.webkitRelativePath || file.name;
            results.push({ file: file, relPath: relPath });
        }
        
        return results;
    }
    
    /**
     * 获取认证头（根据当前端自动选择）
     * @returns {Object}
     */
    function getAuthHeaders() {
        const headers = {
            'Content-Type': 'application/json',
        };
        
        // 桌面端：使用localStorage中的token
        const desktopToken = localStorage.getItem('desktop_token');
        if (desktopToken) {
            headers['Authorization'] = 'Bearer ' + desktopToken;
        }
        
        // 客户门户：使用localStorage中的portal_token
        const portalToken = localStorage.getItem('portal_token');
        if (portalToken) {
            headers['X-Portal-Token'] = portalToken;
        }
        
        return headers;
    }
    
    /**
     * 初始化单个文件上传
     * @param {Object} params 
     * @returns {Promise<Object>}
     */
    async function initUpload(params) {
        const response = await fetch(API_ENDPOINTS.uploadInit, {
            method: 'POST',
            headers: getAuthHeaders(),
            credentials: 'include',
            body: JSON.stringify(params),
        });
        return response.json();
    }
    
    /**
     * 初始化文件夹上传（批量）
     * @param {Object} params 
     * @returns {Promise<Object>}
     */
    async function initFolderUpload(params) {
        console.log('[FU_DEBUG] initFolderUpload 请求:', params);
        const response = await fetch(API_ENDPOINTS.uploadFolderInit, {
            method: 'POST',
            headers: getAuthHeaders(),
            credentials: 'include',
            body: JSON.stringify(params),
        });
        const result = await response.json();
        console.log('[FU_DEBUG] initFolderUpload 响应:', result);
        if (!response.ok) {
            console.error('[FU_DEBUG] HTTP错误:', response.status, result);
        }
        return result;
    }
    
    /**
     * 获取分片上传URL
     * @param {string} uploadId 
     * @param {string} storageKey 
     * @param {number} partNumber 
     * @returns {Promise<Object>}
     */
    async function getPartUploadUrl(uploadId, storageKey, partNumber) {
        const response = await fetch(API_ENDPOINTS.uploadPartUrl, {
            method: 'POST',
            headers: getAuthHeaders(),
            credentials: 'include',
            body: JSON.stringify({
                upload_id: uploadId,
                storage_key: storageKey,
                part_number: partNumber,
            }),
        });
        return response.json();
    }
    
    /**
     * 完成上传
     * @param {Object} params 
     * @returns {Promise<Object>}
     */
    async function completeUpload(params) {
        const response = await fetch(API_ENDPOINTS.uploadComplete, {
            method: 'POST',
            headers: getAuthHeaders(),
            credentials: 'include',
            body: JSON.stringify(params),
        });
        return response.json();
    }
    
    /**
     * 上传单个文件（分片上传）
     * @param {File} file 
     * @param {Object} uploadSession 
     * @param {Function} onProgress 
     * @returns {Promise<Object>}
     */
    async function uploadFile(file, uploadSession, onProgress) {
        const { upload_id, storage_key, part_size, total_parts } = uploadSession;
        const parts = [];
        
        console.log('[FU_DEBUG] uploadFile开始:', { upload_id, storage_key, part_size, total_parts, fileSize: file.size });
        
        for (let partNumber = 1; partNumber <= total_parts; partNumber++) {
            const start = (partNumber - 1) * part_size;
            const end = Math.min(start + part_size, file.size);
            const chunk = file.slice(start, end);
            
            console.log('[FU_DEBUG] 上传分片 partNumber=' + partNumber);
            
            // 通过后端代理上传分片（解决CORS问题）
            const uploadUrl = API_ENDPOINTS.uploadPart + 
                '?upload_id=' + encodeURIComponent(upload_id) +
                '&storage_key=' + encodeURIComponent(storage_key) +
                '&part_number=' + partNumber;
            
            try {
                // 构建认证头（不含Content-Type，因为body是二进制）
                const authHeaders = {};
                const desktopToken = localStorage.getItem('desktop_token');
                if (desktopToken) {
                    authHeaders['Authorization'] = 'Bearer ' + desktopToken;
                }
                const portalToken = localStorage.getItem('portal_token');
                if (portalToken) {
                    authHeaders['X-Portal-Token'] = portalToken;
                }
                
                const uploadResponse = await fetch(uploadUrl, {
                    method: 'POST',
                    headers: authHeaders,
                    credentials: 'include',
                    body: chunk,
                });
                
                const result = await uploadResponse.json();
                
                if (!result.success) {
                    console.error('[FU_DEBUG] 分片上传失败:', result);
                    throw new Error(result.error || '分片上传失败');
                }
                
                console.log('[FU_DEBUG] 分片上传成功, ETag=' + result.data.etag);
                parts.push({
                    PartNumber: partNumber,
                    ETag: result.data.etag,
                });
            } catch (fetchErr) {
                console.error('[FU_DEBUG] fetch异常:', fetchErr);
                throw fetchErr;
            }
            
            if (onProgress) {
                onProgress(partNumber, total_parts);
            }
        }
        
        return parts;
    }
    
    /**
     * 上传文件夹
     * @param {Array<{file: File, relPath: string}>} files 
     * @param {Object} options 
     * @param {Function} onProgress 
     * @returns {Promise<Array>}
     */
    async function uploadFolder(files, options, onProgress) {
        const { groupCode, projectId, assetType } = options;
        
        // 准备文件列表
        const fileList = files.map((f, index) => ({
            rel_path: f.relPath,
            filename: f.file.name,
            filesize: f.file.size,
            mime_type: f.file.type || 'application/octet-stream',
        }));
        
        // 初始化文件夹上传
        const initResult = await initFolderUpload({
            group_code: groupCode,
            project_id: projectId,
            asset_type: assetType,
            files: fileList,
        });
        
        if (!initResult.success) {
            console.error('[FU_DEBUG] 初始化失败:', initResult);
            throw new Error(initResult.error || '初始化上传失败');
        }
        
        console.log('[FU_DEBUG] initResult.data:', initResult.data);
        const { upload_sessions } = initResult.data;
        const results = [];
        
        // 逐个上传文件
        for (let i = 0; i < upload_sessions.length; i++) {
            const session = upload_sessions[i];
            const fileInfo = files[session.index];
            
            if (onProgress) {
                onProgress({
                    type: 'file_start',
                    current: i + 1,
                    total: upload_sessions.length,
                    filename: fileInfo.file.name,
                });
            }
            
            try {
                // 上传文件分片
                const parts = await uploadFile(fileInfo.file, session, (partNum, totalParts) => {
                    if (onProgress) {
                        onProgress({
                            type: 'part_progress',
                            current: i + 1,
                            total: upload_sessions.length,
                            filename: fileInfo.file.name,
                            partNumber: partNum,
                            totalParts: totalParts,
                        });
                    }
                });
                
                // 完成上传
                const completeResult = await completeUpload({
                    upload_id: session.upload_id,
                    storage_key: session.storage_key,
                    parts: parts,
                    project_id: projectId,
                    asset_type: assetType,
                    filename: fileInfo.file.name,
                    filesize: fileInfo.file.size,
                    rel_path: fileInfo.relPath,
                });
                
                results.push({
                    success: completeResult.success,
                    filename: fileInfo.file.name,
                    relPath: fileInfo.relPath,
                    storageKey: session.storage_key,
                    error: completeResult.error,
                });
                
            } catch (error) {
                results.push({
                    success: false,
                    filename: fileInfo.file.name,
                    relPath: fileInfo.relPath,
                    error: error.message,
                });
            }
            
            if (onProgress) {
                onProgress({
                    type: 'file_complete',
                    current: i + 1,
                    total: upload_sessions.length,
                    filename: fileInfo.file.name,
                });
            }
        }
        
        return results;
    }
    
    // 公开API
    return {
        PART_SIZE: PART_SIZE,
        scanFolder: scanFolder,
        extractFilesFromDrop: extractFilesFromDrop,
        extractFilesFromInput: extractFilesFromInput,
        initUpload: initUpload,
        initFolderUpload: initFolderUpload,
        getPartUploadUrl: getPartUploadUrl,
        completeUpload: completeUpload,
        uploadFile: uploadFile,
        uploadFolder: uploadFolder,
    };
})();

// 导出为全局变量
if (typeof window !== 'undefined') {
    window.FolderUploader = FolderUploader;
}

// 支持ES模块
if (typeof module !== 'undefined' && module.exports) {
    module.exports = FolderUploader;
}
