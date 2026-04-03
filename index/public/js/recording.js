/**
 * 录音功能
 * 用于电脑版首通模块的录音功能
 * 重构版本 - 简化逻辑，确保可靠运行
 */

(function() {
    'use strict';
    
    console.log('[Recording] recording.js 开始加载...');
    
    // 录音状态变量
    let mediaRecorder = null;
    let audioChunks = [];
    let recordingStartTime = null;
    let recordingTimer = null;
    let stream = null;
    let isRecording = false;
    let isInitialized = false;
    
    // DOM元素缓存
    let recordBtn = null;
    let stopRecordBtn = null;
    let recordingStatus = null;
    let recordingTimerEl = null;
    
    /**
     * 获取DOM元素
     */
    function getDOMElements() {
        recordBtn = document.getElementById('recordBtn');
        stopRecordBtn = document.getElementById('stopRecordBtn');
        recordingStatus = document.getElementById('recording-status');
        recordingTimerEl = document.getElementById('recording-timer');
        
        return {
            recordBtn,
            stopRecordBtn,
            recordingStatus,
            recordingTimerEl
        };
    }
    
    /**
     * 显示提示信息
     */
    function showAlert(message, type = 'info') {
        if (typeof showAlertModal === 'function') {
            showAlertModal(message, type);
        } else if (typeof alert === 'function') {
            alert(message);
        } else {
            console.log('[Recording]', message);
        }
    }
    
    /**
     * 获取客户ID
     */
    function getCustomerId() {
        // 从URL参数获取
        const urlParams = new URLSearchParams(window.location.search);
        const idFromUrl = urlParams.get('id');
        if (idFromUrl && parseInt(idFromUrl) > 0) {
            return parseInt(idFromUrl);
        }
        
        // 从表单字段获取
        const customerIdInput = document.querySelector('input[name="customer_id"]');
        if (customerIdInput && customerIdInput.value) {
            return parseInt(customerIdInput.value);
        }
        
        // 从全局变量获取
        if (typeof window.customerId !== 'undefined' && window.customerId) {
            return parseInt(window.customerId);
        }
        
        return 0;
    }
    
    /**
     * 获取客户姓名
     */
    function getCustomerName() {
        const customerNameInput = document.querySelector('input[name="name"]');
        return customerNameInput ? customerNameInput.value.trim() : '';
    }
    
    /**
     * 更新计时器显示
     */
    function updateTimer() {
        if (!recordingStartTime || !recordingTimerEl) {
            return;
        }
        
        const elapsed = Math.floor((Date.now() - recordingStartTime) / 1000);
        const minutes = Math.floor(elapsed / 60).toString().padStart(2, '0');
        const seconds = (elapsed % 60).toString().padStart(2, '0');
        
        if (recordingTimerEl) {
            recordingTimerEl.textContent = `${minutes}:${seconds}`;
        }
    }
    
    /**
     * 更新录音UI状态
     */
    function updateRecordingUI(state) {
        const elements = getDOMElements();
        
        switch(state) {
            case 'idle':
                // 初始状态：显示开始按钮，隐藏其他元素
                if (elements.recordBtn) {
                    elements.recordBtn.style.display = 'inline-flex';
                    elements.recordBtn.disabled = false;
                    elements.recordBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="flex-shrink: 0;"><path d="M12 2C10.34 2 9 3.34 9 5v6c0 1.66 1.34 3 3 3s3-1.34 3-3V5c0-1.66-1.34-3-3-3zm0 16c-2.76 0-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-1.08c3.39-.49 6-3.39 6-6.92h-2c0 2.76-2.24 5-5 5z"/></svg><span>开始录音</span>';
                }
                if (elements.recordingStatus) {
                    elements.recordingStatus.style.display = 'none';
                }
                if (elements.stopRecordBtn) {
                    elements.stopRecordBtn.style.display = 'none';
                }
                if (elements.recordingTimerEl) {
                    elements.recordingTimerEl.textContent = '00:00';
                }
                break;
                
            case 'recording':
                // 录音中：禁用开始按钮，显示计时器和停止按钮
                if (elements.recordBtn) {
                    elements.recordBtn.disabled = true;
                }
                if (elements.recordingStatus) {
                    elements.recordingStatus.style.display = 'flex';
                }
                if (elements.stopRecordBtn) {
                    elements.stopRecordBtn.style.display = 'inline-flex';
                    elements.stopRecordBtn.disabled = false;
                }
                break;
                
            case 'stopped':
                // 已停止：恢复开始按钮，隐藏录音状态
                if (elements.recordBtn) {
                    elements.recordBtn.disabled = false;
                    elements.recordBtn.style.display = 'inline-flex';
                    elements.recordBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="flex-shrink: 0;"><path d="M12 2C10.34 2 9 3.34 9 5v6c0 1.66 1.34 3 3 3s3-1.34 3-3V5c0-1.66-1.34-3-3-3zm0 16c-2.76 0-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-1.08c3.39-.49 6-3.39 6-6.92h-2c0 2.76-2.24 5-5 5z"/></svg><span>重新录音</span>';
                }
                if (elements.recordingStatus) {
                    elements.recordingStatus.style.display = 'none';
                }
                if (elements.stopRecordBtn) {
                    elements.stopRecordBtn.style.display = 'none';
                }
                break;
        }
    }
    
    /**
     * 开始录音
     */
    async function startRecording() {
        console.log('[Recording] startRecording 被调用');
        
        // 检查是否已在录音
        if (isRecording) {
            console.warn('[Recording] 已在录音中，忽略重复调用');
            return;
        }
        
        // 检查浏览器支持
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showAlert('无法启动录音！\n\n您的浏览器不支持录音功能，或需要HTTPS环境。', 'error');
            return;
        }
        
        if (typeof MediaRecorder === 'undefined') {
            showAlert('无法启动录音！\n\n您的浏览器不支持MediaRecorder API。', 'error');
            return;
        }
        
        try {
            // 检查客户ID
            const customerId = getCustomerId();
            if (!customerId || customerId <= 0) {
                showAlert('请先保存客户信息才能录音', 'error');
                return;
            }
            
            // 更新UI - 显示请求权限状态
            if (recordBtn) {
                recordBtn.disabled = true;
                recordBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="flex-shrink: 0;"><path d="M12 2C10.34 2 9 3.34 9 5v6c0 1.66 1.34 3 3 3s3-1.34 3-3V5c0-1.66-1.34-3-3-3zm0 16c-2.76 0-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-1.08c3.39-.49 6-3.39 6-6.92h-2c0 2.76-2.24 5-5 5z"/></svg><span>请求权限中...</span>';
            }
            
            // 请求麦克风权限
            console.log('[Recording] 请求麦克风权限...');
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            console.log('[Recording] 麦克风权限获取成功');
            
            // 检测支持的MIME类型，优先使用Windows原生支持的格式
            let mimeType = '';
            // Windows系统优先尝试WAV格式（原生支持，可直接播放）
            if (MediaRecorder.isTypeSupported('audio/wav')) {
                mimeType = 'audio/wav';
                console.log('[Recording] 使用WAV格式（Windows原生支持）');
            } else if (MediaRecorder.isTypeSupported('audio/wave')) {
                mimeType = 'audio/wave';
                console.log('[Recording] 使用WAVE格式');
            } 
            // 其次尝试MP3格式（但大多数浏览器不支持直接录制MP3）
            else if (MediaRecorder.isTypeSupported('audio/mp3')) {
                mimeType = 'audio/mp3';
                console.log('[Recording] 使用MP3格式（直接录制）');
            } else if (MediaRecorder.isTypeSupported('audio/mpeg')) {
                mimeType = 'audio/mpeg';
                console.log('[Recording] 使用MPEG格式');
            }
            // 如果都不支持，使用webm（后端会自动转换为MP3）
            else if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) {
                mimeType = 'audio/webm;codecs=opus';
                console.log('[Recording] 使用WebM格式（后端将自动转换为MP3）');
            } else if (MediaRecorder.isTypeSupported('audio/webm')) {
                mimeType = 'audio/webm';
                console.log('[Recording] 使用WebM格式（后端将自动转换为MP3）');
            }
            
            console.log('[Recording] 最终使用MIME类型:', mimeType || '浏览器默认');
            
            // 创建MediaRecorder
            const options = mimeType ? { mimeType: mimeType } : undefined;
            try {
                mediaRecorder = new MediaRecorder(stream, options);
            } catch (err) {
                console.warn('[Recording] 使用指定MIME类型失败，使用默认配置:', err);
                mediaRecorder = new MediaRecorder(stream);
            }
            
            // 初始化数据
            audioChunks = [];
            
            // 设置事件处理器
            mediaRecorder.ondataavailable = function(event) {
                if (event.data && event.data.size > 0) {
                    audioChunks.push(event.data);
                    console.log('[Recording] 录音数据接收:', event.data.size, 'bytes');
                }
            };
            
            mediaRecorder.onstop = function() {
                console.log('[Recording] 录音结束');
                if (stream) {
                    stream.getTracks().forEach(track => track.stop());
                    stream = null;
                }
                if (recordingTimer) {
                    clearInterval(recordingTimer);
                    recordingTimer = null;
                }
            };
            
            mediaRecorder.onerror = function(event) {
                console.error('[Recording] MediaRecorder错误:', event.error);
                showAlert('录音过程中发生错误: ' + (event.error?.message || '未知错误'), 'error');
                stopRecording();
            };
            
            // 开始录音
            console.log('[Recording] 开始录音...');
            mediaRecorder.start(1000); // 每1秒触发一次dataavailable事件
            recordingStartTime = Date.now();
            isRecording = true;
            
            // 更新UI
            updateRecordingUI('recording');
            
            // 启动计时器
            recordingTimer = setInterval(updateTimer, 1000);
            updateTimer(); // 立即更新一次
            
            console.log('[Recording] 录音已启动');
            
        } catch (err) {
            console.error('[Recording] 录音启动失败:', err);
            isRecording = false;
            
            // 恢复UI
            updateRecordingUI('idle');
            
            // 显示错误信息
            let errorMessage = '录音启动失败';
            if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                errorMessage = '麦克风权限被拒绝。请在浏览器设置中允许麦克风访问。';
            } else if (err.name === 'NotFoundError') {
                errorMessage = '未检测到麦克风设备，请检查设备连接';
            } else if (err.name === 'NotReadableError') {
                errorMessage = '麦克风正在被其他应用使用，请关闭其他应用后重试';
            } else {
                errorMessage = '无法访问麦克风: ' + (err.message || '未知错误');
            }
            
            showAlert(errorMessage, 'error');
        }
    }
    
    /**
     * 停止录音（不立即保存，等待表单提交时一起保存）
     */
    async function stopRecording() {
        console.log('[Recording] stopRecording 被调用');
        
        if (!isRecording || !mediaRecorder || mediaRecorder.state === 'inactive') {
            console.warn('[Recording] 未在录音中，忽略停止请求');
            return;
        }
        
        try {
            // 停止录音
            mediaRecorder.stop();
            isRecording = false;
            
            // 更新UI - 停止按钮
            if (stopRecordBtn) {
                stopRecordBtn.disabled = true;
                stopRecordBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="flex-shrink: 0;"><rect x="6" y="6" width="12" height="12" rx="2"/></svg><span>已停止</span>';
            }
            
            // 等待录音数据收集完成
            await new Promise((resolve) => {
                const checkStop = () => {
                    if (mediaRecorder.state === 'inactive') {
                        resolve();
                    } else {
                        setTimeout(checkStop, 100);
                    }
                };
                checkStop();
                // 超时保护
                setTimeout(resolve, 1000);
            });
            
            // 额外等待确保数据完全收集
            await new Promise(resolve => setTimeout(resolve, 200));
            
            console.log('[Recording] 录音已停止，数据块数量:', audioChunks.length);
            
            // 检查录音数据是否为空
            if (audioChunks.length === 0) {
                console.warn('[Recording] 录音数据为空');
                showAlert('录音数据为空，请重新录音', 'error');
                updateRecordingUI('idle');
                return;
            }
            
            // 创建音频Blob（使用实际录制时的MIME类型）
            const actualMimeType = mediaRecorder && mediaRecorder.mimeType ? mediaRecorder.mimeType : 'audio/webm';
            const audioBlob = new Blob(audioChunks, { type: actualMimeType });
            if (audioBlob.size === 0) {
                console.warn('[Recording] 录音文件大小为0');
                showAlert('录音文件为空，请重新录音', 'error');
                updateRecordingUI('idle');
                return;
            }
            
            // 获取客户信息
            const customerId = getCustomerId();
            const customerName = getCustomerName();
            const now = new Date();
            const dateStr = `${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}${String(now.getDate()).padStart(2, '0')}`;
            
            // 根据实际使用的MIME类型确定文件扩展名
            let extension = 'webm'; // 默认扩展名
            if (mediaRecorder && mediaRecorder.mimeType) {
                const actualMimeType = mediaRecorder.mimeType.toLowerCase();
                if (actualMimeType.includes('wav') || actualMimeType.includes('wave')) {
                    extension = 'wav';
                } else if (actualMimeType.includes('mp3') || actualMimeType.includes('mpeg')) {
                    extension = 'mp3';
                } else if (actualMimeType.includes('webm')) {
                    extension = 'webm';
                }
            }
            
            const fileName = customerName ? `录音${dateStr}_${customerName}.${extension}` : `录音${dateStr}_客户.${extension}`;
            
            // 将录音数据存储到全局变量
            window.recordingAudioBlob = audioBlob;
            window.recordingAudioFilename = fileName;
            
            // 恢复UI
            updateRecordingUI('stopped');
            
            // 如果客户已创建（customerId > 0），自动上传到文件管理
            if (customerId && customerId > 0) {
                console.log('[Recording] 客户已创建，自动上传录音文件到文件管理');
                
                // 自动上传
                uploadRecordingFile(audioBlob, fileName, customerId).then(() => {
                    // 上传成功，清理暂存数据
                    window.recordingAudioBlob = null;
                    window.recordingAudioFilename = null;
                    
                    // 显示成功提示
                    showAlert('录音已保存到文件管理', 'success');
                    
                    // 如果当前在文件管理模块，触发刷新
                    if (typeof window.refreshFileList === 'function') {
                        window.refreshFileList();
                    }
                }).catch(err => {
                    console.error('[Recording] 自动上传失败:', err);
                    // 上传失败，保留数据等待手动保存
                    showAlert('上传失败，点击保存按钮将录音和记录一起保存', 'error');
                });
            } else {
                // 客户未创建，等待表单提交时一起保存
                console.log('[Recording] 客户未创建，录音数据已暂存，等待表单保存时一起提交:', fileName);
                showAlert('录音已停止，点击保存按钮将录音和记录一起保存', 'info');
            }
            
        } catch (err) {
            console.error('[Recording] 停止录音失败:', err);
            showAlert('停止录音失败: ' + (err.message || '未知错误'), 'error');
            updateRecordingUI('stopped');
        }
    }
    
    /**
     * 上传录音文件到文件管理
     */
    async function uploadRecordingFile(audioBlob, fileName, customerId) {
        console.log('[Recording] 开始上传录音文件:', fileName);
        
        // 创建File对象
        const audioFile = new File([audioBlob], fileName, { type: 'audio/webm', lastModified: Date.now() });
        
        // 准备上传数据
        const formData = new FormData();
        formData.append('customer_id', customerId);
        formData.append('category', 'client_material');
        formData.append('upload_source', 'first_contact');
        formData.append('files[]', audioFile);
        
        try {
            // 上传文件
            const response = await fetch('/api/customer_files.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`HTTP ${response.status}: ${errorText}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                console.log('[Recording] 录音文件上传成功:', data);
                return Promise.resolve(data);
            } else {
                throw new Error(data.message || '上传失败');
            }
            
        } catch (err) {
            console.error('[Recording] 上传失败:', err);
            
            // 如果fetch失败，尝试使用XMLHttpRequest作为降级方案
            if (err.message && (err.message.includes('insecure') || err.message.includes('Failed to fetch'))) {
                console.log('[Recording] 尝试使用XMLHttpRequest作为降级方案');
                
                return new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', '/api/customer_files.php', true);
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            try {
                                const data = JSON.parse(xhr.responseText);
                                if (data.success) {
                                    console.log('[Recording] 录音文件上传成功（XHR）');
                                    resolve(data);
                                } else {
                                    reject(new Error(data.message || '上传失败'));
                                }
                            } catch (parseError) {
                                reject(new Error('服务器响应格式错误'));
                            }
                        } else {
                            reject(new Error(`HTTP ${xhr.status}`));
                        }
                    };
                    
                    xhr.onerror = function() {
                        reject(new Error('网络连接失败'));
                    };
                    
                    xhr.send(formData);
                });
            } else {
                throw err;
            }
        }
    }
    
    /**
     * 保存录音（保留用于向后兼容，但实际不再使用）
     */
    async function saveRecording() {
        console.log('[Recording] saveRecording 被调用');
        
        // 检查是否有录音数据
        if (audioChunks.length === 0) {
            console.warn('[Recording] 录音数据为空');
            showAlert('录音数据为空，请重新录音', 'error');
            updateRecordingUI('idle');
            return;
        }
        
        // 检查客户姓名
        const customerName = getCustomerName();
        if (!customerName) {
            showAlert('请先填写客户姓名才能保存录音', 'error');
            return;
        }
        
        // 检查客户ID
        const customerId = getCustomerId();
        if (!customerId || customerId <= 0) {
            showAlert('请先保存客户信息', 'error');
            return;
        }
        
        // 创建音频Blob
        const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
        console.log('[Recording] 音频Blob创建成功，大小:', audioBlob.size, 'bytes');
        
        if (audioBlob.size === 0) {
            showAlert('录音文件为空，请重新录音', 'error');
            updateRecordingUI('idle');
            audioChunks = [];
            return;
        }
        
        // 生成文件名
        const now = new Date();
        const dateStr = `${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}${String(now.getDate()).padStart(2, '0')}`;
        const fileName = `录音${dateStr}_${customerName}.webm`;
        
        // 创建File对象
        const audioFile = new File([audioBlob], fileName, { type: 'audio/webm' });
        
        // 准备上传数据
        const formData = new FormData();
        formData.append('customer_id', customerId);
        formData.append('category', 'client_material');
        formData.append('upload_source', 'first_contact');
        formData.append('files[]', audioFile);
        
        console.log('[Recording] 准备上传录音文件:', {
            fileName: fileName,
            fileSize: audioBlob.size,
            customerId: customerId
        });
        
        try {
            // 上传文件
            const response = await fetch('/api/customer_files.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`HTTP ${response.status}: ${errorText}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                console.log('[Recording] 录音上传成功:', data);
                showAlert('录音完成，已保存在文件管理', 'success');
                
                // 清理数据
                audioChunks = [];
                updateRecordingUI('idle');
                
                // 如果当前在文件管理模块，触发刷新
                if (typeof window.refreshFileList === 'function') {
                    window.refreshFileList();
                }
            } else {
                throw new Error(data.message || '上传失败');
            }
            
        } catch (err) {
            console.error('[Recording] 上传失败:', err);
            
            // 尝试使用XMLHttpRequest作为降级方案
            if (err.message && (err.message.includes('insecure') || err.message.includes('Failed to fetch'))) {
                console.log('[Recording] 尝试使用XMLHttpRequest作为降级方案');
                
                return new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', '/api/customer_files.php', true);
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            try {
                                const data = JSON.parse(xhr.responseText);
                                if (data.success) {
                                    showAlert('录音完成，已保存在文件管理', 'success');
                                    audioChunks = [];
                                    updateRecordingUI('idle');
                                    if (typeof window.refreshFileList === 'function') {
                                        window.refreshFileList();
                                    }
                                    resolve();
                                } else {
                                    reject(new Error(data.message || '上传失败'));
                                }
                            } catch (parseError) {
                                reject(new Error('服务器响应格式错误'));
                            }
                        } else {
                            reject(new Error(`HTTP ${xhr.status}`));
                        }
                    };
                    
                    xhr.onerror = function() {
                        reject(new Error('网络连接失败'));
                    };
                    
                    xhr.send(formData);
                });
            } else {
                showAlert('上传失败: ' + (err.message || '未知错误'), 'error');
            }
        }
    }
    
    /**
     * 初始化录音功能
     */
    function initRecording() {
        console.log('[Recording] initRecording 被调用');
        
        // 避免重复初始化
        if (isInitialized) {
            console.log('[Recording] 已初始化，跳过');
            return;
        }
        
        // 获取DOM元素
        const elements = getDOMElements();
        
        if (!elements.recordBtn) {
            console.log('[Recording] 录音按钮不存在，可能不在首通模块');
            return;
        }
        
        // 标记已初始化
        isInitialized = true;
        
        // 绑定开始录音按钮事件
        elements.recordBtn.onclick = function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('[Recording] 开始录音按钮被点击');
            startRecording();
            return false;
        };
        
        // 绑定停止录音按钮事件
        if (elements.stopRecordBtn) {
            elements.stopRecordBtn.onclick = function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('[Recording] 停止录音按钮被点击');
                stopRecording();
                return false;
            };
        }
        
        // 初始化UI状态
        updateRecordingUI('idle');
        
        console.log('[Recording] 录音功能初始化完成');
    }
    
    // 暴露函数到全局作用域
    window.initRecording = initRecording;
    window.startRecording = startRecording;
    window.stopRecording = stopRecording;
    
    console.log('[Recording] recording.js 加载完成，函数已暴露到全局:', {
        hasInitRecording: typeof window.initRecording === 'function',
        hasStartRecording: typeof window.startRecording === 'function',
        hasStopRecording: typeof window.stopRecording === 'function'
    });
    
    // 自动初始化（如果DOM已准备好）
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            console.log('[Recording] DOMContentLoaded - 尝试自动初始化');
            setTimeout(initRecording, 300);
        });
    } else {
        console.log('[Recording] DOM已加载 - 尝试自动初始化');
        setTimeout(initRecording, 300);
    }
    
    // 页面完全加载后再试一次
    window.addEventListener('load', function() {
        console.log('[Recording] window.load - 尝试自动初始化');
        setTimeout(initRecording, 500);
    });
    
})();
