/**
 * 统一文件传输 SDK
 * 
 * 提供统一的上传/下载接口，自动处理小文件直传和大文件分片
 * 支持进度回调、错误重试、断点续传
 * 
 * 使用示例:
 * const transfer = new FileTransfer({ apiBase: '/api/' });
 * 
 * // 上传
 * transfer.upload(file, { storageKey: 'path/to/file.ext' })
 *   .onProgress((info) => console.log(info.progress + '%'))
 *   .then((result) => console.log('完成', result))
 *   .catch((err) => console.error('失败', err));
 * 
 * // 下载
 * transfer.download(url, filename)
 *   .onProgress((info) => console.log(info.progress + '%'))
 *   .then(() => console.log('下载完成'));
 */

class FileTransfer {
    constructor(options = {}) {
        this.apiBase = options.apiBase || '/api/';
        this.chunkThreshold = options.chunkThreshold || 2 * 1024 * 1024 * 1024; // 2GB
        this.maxRetries = options.maxRetries || 3;
        this.retryDelay = options.retryDelay || 1000;
        this.progressInterval = options.progressInterval || 500; // 进度轮询间隔
    }
    
    /**
     * 上传文件
     * @param {File} file 文件对象
     * @param {Object} options 选项
     * @returns {TransferTask}
     */
    upload(file, options = {}) {
        return new UploadTask(this, file, options);
    }
    
    /**
     * 下载文件
     * @param {string} url 文件URL
     * @param {string} filename 保存的文件名
     * @returns {TransferTask}
     */
    download(url, filename) {
        return new DownloadTask(this, url, filename);
    }
    
    /**
     * 获取当前传输模式
     * @returns {Promise<Object>}
     */
    async getMode() {
        const response = await fetch(`${this.apiBase}file_transfer.php?action=mode`);
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || '获取模式失败');
        }
        return result.data;
    }
    
    /**
     * 获取传输进度
     * @param {string} transferId 传输ID
     * @returns {Promise<Object>}
     */
    async getProgress(transferId) {
        const response = await fetch(`${this.apiBase}file_transfer.php?action=progress&transfer_id=${encodeURIComponent(transferId)}`);
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || '获取进度失败');
        }
        return result.data;
    }
}

/**
 * 上传任务
 */
class UploadTask {
    constructor(transfer, file, options) {
        this.transfer = transfer;
        this.file = file;
        this.options = options;
        this.transferId = null;
        this.mode = null;
        this.chunked = false;
        this.uploadId = null;
        this.totalParts = 0;
        this.chunkSize = 0;
        this.storageKey = options.storageKey || '';
        this.aborted = false;
        
        this._progressCallback = null;
        this._completeCallback = null;
        this._errorCallback = null;
        this._progressTimer = null;
        
        // 自动开始执行
        this._promise = this._execute();
    }
    
    /**
     * 设置进度回调
     */
    onProgress(callback) {
        this._progressCallback = callback;
        return this;
    }
    
    /**
     * 设置完成回调
     */
    onComplete(callback) {
        this._completeCallback = callback;
        return this;
    }
    
    /**
     * 设置错误回调
     */
    onError(callback) {
        this._errorCallback = callback;
        return this;
    }
    
    /**
     * Promise then
     */
    then(onFulfilled, onRejected) {
        return this._promise.then(onFulfilled, onRejected);
    }
    
    /**
     * Promise catch
     */
    catch(onRejected) {
        return this._promise.catch(onRejected);
    }
    
    /**
     * 取消上传
     */
    abort() {
        this.aborted = true;
        if (this._progressTimer) {
            clearInterval(this._progressTimer);
        }
    }
    
    /**
     * 执行上传
     */
    async _execute() {
        try {
            // 1. 初始化上传
            const initResult = await this._initUpload();
            this.transferId = initResult.transfer_id;
            this.mode = initResult.mode;
            this.chunked = initResult.chunked;
            this.uploadId = initResult.upload_id;
            this.totalParts = initResult.total_parts;
            this.chunkSize = initResult.chunk_size;
            
            // 开始进度轮询
            this._startProgressPolling();
            
            // 2. 根据模式上传
            if (this.chunked) {
                // 大文件分片上传
                await this._uploadChunked();
            } else {
                // 小文件直传
                await this._uploadDirect();
            }
            
            // 3. 完成上传
            const completeResult = await this._completeUpload();
            
            // 停止进度轮询
            this._stopProgressPolling();
            
            // 触发完成回调
            if (this._completeCallback) {
                this._completeCallback(completeResult);
            }
            
            return completeResult;
            
        } catch (error) {
            this._stopProgressPolling();
            if (this._errorCallback) {
                this._errorCallback(error);
            }
            throw error;
        }
    }
    
    /**
     * 初始化上传
     */
    async _initUpload() {
        const response = await fetch(`${this.transfer.apiBase}file_transfer.php?action=init`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                filename: this.file.name,
                filesize: this.file.size,
                storage_key: this.storageKey,
                mime_type: this.file.type || 'application/octet-stream',
            })
        });
        
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || '初始化上传失败');
        }
        
        return result.data;
    }
    
    /**
     * 大文件分片上传
     */
    async _uploadChunked() {
        for (let partNumber = 1; partNumber <= this.totalParts; partNumber++) {
            if (this.aborted) {
                throw new Error('上传已取消');
            }
            
            const start = (partNumber - 1) * this.chunkSize;
            const end = Math.min(start + this.chunkSize, this.file.size);
            const chunk = this.file.slice(start, end);
            
            // 带重试的分片上传
            await this._uploadChunkWithRetry(partNumber, chunk);
        }
    }
    
    /**
     * 上传单个分片（带重试）
     */
    async _uploadChunkWithRetry(partNumber, chunk, retryCount = 0) {
        try {
            const url = `${this.transfer.apiBase}file_transfer.php?action=chunk&transfer_id=${encodeURIComponent(this.transferId)}&part_number=${partNumber}`;
            
            const response = await fetch(url, {
                method: 'POST',
                body: chunk
            });
            
            const result = await response.json();
            if (!result.success) {
                throw new Error(result.message || `分片 ${partNumber} 上传失败`);
            }
            
            return result.data;
            
        } catch (error) {
            if (retryCount < this.transfer.maxRetries) {
                await this._delay(this.transfer.retryDelay * (retryCount + 1));
                return this._uploadChunkWithRetry(partNumber, chunk, retryCount + 1);
            }
            throw error;
        }
    }
    
    /**
     * 小文件直传
     */
    async _uploadDirect() {
        const formData = new FormData();
        formData.append('transfer_id', this.transferId);
        formData.append('file', this.file);
        
        const response = await fetch(`${this.transfer.apiBase}file_transfer.php?action=direct`, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || '上传失败');
        }
        
        return result.data;
    }
    
    /**
     * 完成上传
     */
    async _completeUpload() {
        const response = await fetch(`${this.transfer.apiBase}file_transfer.php?action=complete`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                transfer_id: this.transferId
            })
        });
        
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || '完成上传失败');
        }
        
        return result.data;
    }
    
    /**
     * 开始进度轮询
     */
    _startProgressPolling() {
        if (!this._progressCallback) return;
        
        this._progressTimer = setInterval(async () => {
            try {
                const progress = await this.transfer.getProgress(this.transferId);
                this._progressCallback({
                    transferId: this.transferId,
                    filename: this.file.name,
                    status: progress.status,
                    progress: progress.progress,
                    transferred: progress.transferred,
                    total: progress.total_size,
                    speed: progress.speed,
                    eta: progress.eta,
                    speedFormatted: this._formatBytes(progress.speed || 0) + '/s',
                    transferredFormatted: this._formatBytes(progress.transferred || 0),
                    totalFormatted: this._formatBytes(progress.total_size || 0),
                });
            } catch (e) {
                // 忽略轮询错误
            }
        }, this.transfer.progressInterval);
    }
    
    /**
     * 停止进度轮询
     */
    _stopProgressPolling() {
        if (this._progressTimer) {
            clearInterval(this._progressTimer);
            this._progressTimer = null;
        }
    }
    
    /**
     * 延迟
     */
    _delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
    
    /**
     * 格式化字节数
     */
    _formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
}

/**
 * 下载任务
 */
class DownloadTask {
    constructor(transfer, url, filename) {
        this.transfer = transfer;
        this.url = url;
        this.filename = filename;
        this.aborted = false;
        
        this._progressCallback = null;
        this._completeCallback = null;
        this._errorCallback = null;
        
        this._promise = this._execute();
    }
    
    onProgress(callback) {
        this._progressCallback = callback;
        return this;
    }
    
    onComplete(callback) {
        this._completeCallback = callback;
        return this;
    }
    
    onError(callback) {
        this._errorCallback = callback;
        return this;
    }
    
    then(onFulfilled, onRejected) {
        return this._promise.then(onFulfilled, onRejected);
    }
    
    catch(onRejected) {
        return this._promise.catch(onRejected);
    }
    
    abort() {
        this.aborted = true;
    }
    
    async _execute() {
        try {
            // 通过代理下载
            const proxyUrl = `${this.transfer.apiBase}file_transfer.php?action=download&url=${encodeURIComponent(this.url)}&filename=${encodeURIComponent(this.filename || '')}`;
            
            // 使用 fetch 下载并监听进度
            const response = await fetch(proxyUrl);
            
            if (!response.ok) {
                throw new Error(`下载失败: HTTP ${response.status}`);
            }
            
            const contentLength = response.headers.get('content-length');
            const total = contentLength ? parseInt(contentLength, 10) : 0;
            
            // 读取响应流
            const reader = response.body.getReader();
            const chunks = [];
            let received = 0;
            
            while (true) {
                const { done, value } = await reader.read();
                
                if (done) break;
                
                if (this.aborted) {
                    reader.cancel();
                    throw new Error('下载已取消');
                }
                
                chunks.push(value);
                received += value.length;
                
                if (this._progressCallback && total > 0) {
                    this._progressCallback({
                        filename: this.filename,
                        status: 'downloading',
                        progress: Math.round((received / total) * 100),
                        transferred: received,
                        total: total,
                    });
                }
            }
            
            // 合并数据并创建下载
            const blob = new Blob(chunks);
            const downloadUrl = URL.createObjectURL(blob);
            
            const a = document.createElement('a');
            a.href = downloadUrl;
            a.download = this.filename || 'download';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(downloadUrl);
            
            if (this._completeCallback) {
                this._completeCallback({ filename: this.filename, size: received });
            }
            
            return { success: true, filename: this.filename, size: received };
            
        } catch (error) {
            if (this._errorCallback) {
                this._errorCallback(error);
            }
            throw error;
        }
    }
}

// 导出到全局
if (typeof window !== 'undefined') {
    window.FileTransfer = FileTransfer;
    window.UploadTask = UploadTask;
    window.DownloadTask = DownloadTask;
}

// 支持 ES Module
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { FileTransfer, UploadTask, DownloadTask };
}
