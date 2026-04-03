/**
 * 小型附件上传组件
 * 用于首通和异议处理模块的紧凑上传功能
 */
(function() {
    'use strict';

    /**
     * 初始化附件上传组件
     * @param {Object} options 配置选项
     * @param {string} options.containerId - 容器元素ID
     * @param {number} options.customerId - 客户ID
     * @param {string} options.uploadSource - 上传来源: 'first_contact' 或 'objection'
     * @param {boolean} options.isReadonly - 是否只读模式
     */
    function initAttachmentUpload(options) {
        const container = document.getElementById(options.containerId);
        if (!container) {
            console.error('附件上传容器不存在:', options.containerId);
            return;
        }

        const customerId = options.customerId || 0;
        const uploadSource = options.uploadSource;
        const isReadonly = options.isReadonly || false;

        // 确保容器是竖着排列的
        container.style.cssText = 'display: flex; flex-direction: column; gap: 8px;';

        // 创建拖拽上传区域
        const dropzone = createDropzone(isReadonly);
        container.appendChild(dropzone);

        // 创建文件列表容器（竖着排列）
        const fileListContainer = document.createElement('div');
        fileListContainer.className = 'attachment-file-list';
        fileListContainer.style.cssText = 'display: flex; flex-direction: column; gap: 4px;';
        container.appendChild(fileListContainer);

        // 创建隐藏的文件输入
        const fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.multiple = true;
        fileInput.style.display = 'none';
        dropzone.appendChild(fileInput);

        // 绑定事件
        if (!isReadonly) {
            // 设置 dropzone 可聚焦，以便接收粘贴事件
            dropzone.setAttribute('tabindex', '0');
            dropzone.style.outline = 'none';
            
            // 单击时获取焦点，不打开文件选择对话框（用于粘贴）
            dropzone.addEventListener('click', (e) => {
                // 如果点击的是文件输入框，不处理
                if (e.target === fileInput) return;
                // 让 dropzone 获得焦点，以便可以粘贴
                dropzone.focus();
            });

            // 双击选择文件
            dropzone.addEventListener('dblclick', () => {
                fileInput.click();
            });

            // Ctrl+V 粘贴文件
            dropzone.addEventListener('paste', (e) => {
                e.preventDefault();
                const items = e.clipboardData?.items;
                if (!items) return;

                const files = [];
                for (let i = 0; i < items.length; i++) {
                    const item = items[i];
                    if (item.kind === 'file') {
                        const file = item.getAsFile();
                        if (file) {
                            files.push(file);
                        }
                    }
                }

                if (files.length > 0) {
                    handleFileSelect(files, customerId, uploadSource, fileListContainer);
                }
            });

            // 支持在页面上按 Ctrl+V 粘贴（当焦点在容器内时）
            container.addEventListener('paste', (e) => {
                // 检查是否按下了 Ctrl 键（Windows/Linux）或 Cmd 键（Mac）
                if (e.ctrlKey || e.metaKey) {
                    e.preventDefault();
                    const items = e.clipboardData?.items;
                    if (!items) return;

                    const files = [];
                    for (let i = 0; i < items.length; i++) {
                        const item = items[i];
                        if (item.kind === 'file') {
                            const file = item.getAsFile();
                            if (file) {
                                files.push(file);
                            }
                        }
                    }

                    if (files.length > 0) {
                        handleFileSelect(files, customerId, uploadSource, fileListContainer);
                    }
                }
            });

            fileInput.addEventListener('change', (e) => {
                handleFileSelect(e.target.files, customerId, uploadSource, fileListContainer);
                // 清空input，允许重复选择相同文件
                e.target.value = '';
            });

            // 拖拽上传
            ['dragenter', 'dragover'].forEach((evt) => {
                dropzone.addEventListener(evt, (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    dropzone.classList.add('dragover');
                });
            });

            ['dragleave', 'drop'].forEach((evt) => {
                dropzone.addEventListener(evt, (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    if (evt === 'drop' && event.dataTransfer?.files?.length) {
                        handleFileSelect(event.dataTransfer.files, customerId, uploadSource, fileListContainer);
                    }
                    dropzone.classList.remove('dragover');
                });
            });
        }

        // 加载已有文件列表（仅当有客户ID时）
        if (customerId && customerId > 0) {
            loadFileList(customerId, uploadSource, fileListContainer, isReadonly);
        }
    }

    /**
     * 创建拖拽上传区域（小型版本）
     */
    function createDropzone(isReadonly) {
        const dropzone = document.createElement('div');
        dropzone.className = 'attachment-upload-dropzone';
        if (isReadonly) {
            dropzone.style.opacity = '0.5';
            dropzone.style.pointerEvents = 'none';
        }

        // 图标
        const icon = document.createElement('div');
        icon.className = 'attachment-upload-icon';
        icon.textContent = '⬆️';
        dropzone.appendChild(icon);

        // 提示文字
        const tip = document.createElement('p');
        tip.className = 'attachment-upload-tip';
        tip.style.cssText = 'margin: 0; font-size: 12px; color: #6b7280;';
        tip.textContent = '拖拽文件到此处、双击选择文件或单击后按 Ctrl+V 粘贴';
        dropzone.appendChild(tip);

        return dropzone;
    }

    /**
     * 处理文件选择
     */
    function handleFileSelect(files, customerId, uploadSource, container) {
        if (!files || files.length === 0) return;

        const fileArray = Array.from(files);
        uploadFiles(fileArray, customerId, uploadSource, container);
    }

    /**
     * 上传文件
     */
    function uploadFiles(files, customerId, uploadSource, container) {
        // 检查客户ID，如果没有则检查客户姓名
        if (!customerId || customerId <= 0) {
            // 查找表单中的客户姓名字段
            const nameInput = document.querySelector('input[name="name"]');
            const customerName = nameInput ? nameInput.value.trim() : '';
            
            if (!customerName) {
                showToast('请先输入客户姓名并保存客户信息后再上传文件', 'warning');
                // 聚焦到姓名字段
                if (nameInput) {
                    nameInput.focus();
                    nameInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return;
            } else {
                showToast('请先保存客户信息后再上传文件', 'warning');
                return;
            }
        }

        const formData = new FormData();
        formData.append('customer_id', customerId);
        formData.append('category', 'client_material');
        formData.append('upload_source', uploadSource);
        
        // 根据 uploadSource 设置文件夹路径，与查询时保持一致
        const folderPath = uploadSource === 'first_contact' ? '首通附件' : '异议附件';
        formData.append('folder_root', folderPath);

        // API 期望 files 作为文件数组
        files.forEach((file) => {
            formData.append('files[]', file);
        });

        // 显示上传进度
        const progressContainer = createProgressContainer(files.length);
        container.appendChild(progressContainer);

        // 发送上传请求
        const xhr = new XMLHttpRequest();

        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                updateProgress(progressContainer, percent, files.length);
            }
        });

        xhr.addEventListener('load', () => {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        // 移除进度条
                        progressContainer.remove();
                        // 刷新文件列表
                        loadFileList(customerId, uploadSource, container, false);
                        // 显示成功提示
                        showToast('文件上传成功', 'success');
                    } else {
                        progressContainer.remove();
                        showToast(response.message || '上传失败', 'error');
                    }
                } catch (e) {
                    progressContainer.remove();
                    showToast('解析响应失败', 'error');
                }
            } else {
                progressContainer.remove();
                let message = '上传失败';
                try {
                    const response = JSON.parse(xhr.responseText);
                    message = response.message || message;
                } catch (e) {
                    // 忽略解析错误
                }
                showToast(message, 'error');
            }
        });

        xhr.addEventListener('error', () => {
            progressContainer.remove();
            showToast('网络错误，上传失败', 'error');
        });

        xhr.open('POST', '../api/customer_files.php');
        xhr.send(formData);
    }

    /**
     * 创建进度容器
     */
    function createProgressContainer(fileCount) {
        const container = document.createElement('div');
        container.className = 'attachment-upload-progress';
        container.style.cssText = 'margin: 8px 0; padding: 8px; background: #f8f9fa; border-radius: 4px;';
        
        const text = document.createElement('div');
        text.style.cssText = 'font-size: 12px; color: #666; margin-bottom: 4px;';
        text.textContent = `正在上传 ${fileCount} 个文件...`;
        container.appendChild(text);

        const progressBar = document.createElement('div');
        progressBar.style.cssText = 'width: 100%; height: 6px; background: #e9ecef; border-radius: 3px; overflow: hidden;';
        
        const progressFill = document.createElement('div');
        progressFill.style.cssText = 'height: 100%; background: #0d6efd; width: 0%; transition: width 0.3s;';
        progressFill.className = 'progress-fill';
        progressBar.appendChild(progressFill);
        container.appendChild(progressBar);

        container.progressFill = progressFill;
        container.text = text;
        return container;
    }

    /**
     * 更新进度
     */
    function updateProgress(container, percent, fileCount) {
        if (container.progressFill) {
            container.progressFill.style.width = percent + '%';
        }
        if (container.text) {
            container.text.textContent = `正在上传 ${fileCount} 个文件... ${percent}%`;
        }
    }

    /**
     * 加载文件列表
     */
    function loadFileList(customerId, uploadSource, container, isReadonly) {
        // 如果没有客户ID，不加载文件列表
        if (!customerId || customerId <= 0) {
            return;
        }
        
        // 根据 uploadSource 确定文件夹路径
        const folderPath = uploadSource === 'first_contact' ? '首通附件' : '异议附件';
        
        const url = `../api/customer_files.php?customer_id=${customerId}&category=client_material&folder_path=${encodeURIComponent(folderPath)}&include_children=0`;
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                // API返回的是items而不是files
                const files = data.data?.items || data.data?.files || [];
                if (data.success && files.length > 0) {
                    renderFileList(files, container, customerId, isReadonly);
                } else {
                    // 清空列表
                    const existingList = container.querySelector('.attachment-file-items');
                    if (existingList) {
                        existingList.remove();
                    }
                }
            })
            .catch(error => {
                console.error('加载文件列表失败:', error);
            });
    }

    /**
     * 渲染文件列表
     */
    function renderFileList(files, container, customerId, isReadonly) {
        // 移除旧列表
        const existingList = container.querySelector('.attachment-file-items');
        if (existingList) {
            existingList.remove();
        }

        if (!files || files.length === 0) {
            return;
        }

        const listContainer = document.createElement('div');
        listContainer.className = 'attachment-file-items';
        listContainer.style.cssText = 'margin-top: 8px; display: flex; flex-direction: column; gap: 4px;';

        // 从容器中获取 uploadSource（通过查找父容器）
        const uploadSource = container.id === 'first-contact-attachment-upload' ? 'first_contact' : 'objection';
        
        files.forEach((file) => {
            const fileItem = createFileItem(file, customerId, isReadonly, () => {
                // 删除后刷新列表
                loadFileList(customerId, uploadSource, container, isReadonly);
            });
            listContainer.appendChild(fileItem);
        });

        container.appendChild(listContainer);
    }

    /**
     * 创建文件项
     */
    function createFileItem(file, customerId, isReadonly, onDelete) {
        const item = document.createElement('div');
        item.className = 'attachment-file-item';
        item.style.cssText = 'display: flex; flex-direction: column; align-items: flex-start; padding: 8px; background: #f8f9fa; border-radius: 4px; font-size: 12px; width: 100%;';

        // 文件名和操作按钮在同一行
        const topRow = document.createElement('div');
        topRow.style.cssText = 'display: flex; align-items: center; justify-content: space-between; width: 100%; margin-bottom: 4px;';

        const fileName = document.createElement('a');
        fileName.href = `../api/customer_file_stream.php?file_id=${file.id}`;
        fileName.target = '_blank';
        fileName.style.cssText = 'color: #0d6efd; text-decoration: none; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;';
        fileName.textContent = file.filename || file.name || '未知文件';
        fileName.title = file.filename || file.name || '未知文件';
        topRow.appendChild(fileName);

        if (!isReadonly) {
            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'btn btn-sm';
            deleteBtn.style.cssText = 'padding: 2px 8px; font-size: 11px; color: #dc3545; border: 1px solid #dc3545; background: white; margin-left: 8px; flex-shrink: 0;';
            deleteBtn.textContent = '删除';
            deleteBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                if (typeof showConfirmModal === 'function') {
                    showConfirmModal('删除文件', '确定要删除这个文件吗？', function() {
                        deleteFile(file.id, onDelete);
                    });
                } else if (confirm('确定要删除这个文件吗？')) {
                    deleteFile(file.id, onDelete);
                }
            });
            topRow.appendChild(deleteBtn);
        }

        item.appendChild(topRow);

        // 文件信息在下一行
        const fileInfo = document.createElement('span');
        fileInfo.style.cssText = 'color: #666; font-size: 11px;';
        fileInfo.textContent = formatFileSize(file.filesize || file.size || 0) + ' · ' + formatDate(file.uploaded_at || file.upload_time || 0);
        item.appendChild(fileInfo);

        return item;
    }

    /**
     * 删除文件
     */
    function deleteFile(fileId, callback) {
        const formData = new FormData();
        formData.append('id', fileId);
        
        fetch(`../api/customer_file_delete.php`, {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('文件已删除', 'success');
                    if (callback) callback();
                } else {
                    showToast(data.message || '删除失败', 'error');
                }
            })
            .catch(error => {
                console.error('删除文件失败:', error);
                showToast('删除失败', 'error');
            });
    }

    /**
     * 格式化文件大小
     */
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }

    /**
     * 格式化日期
     */
    function formatDate(timestamp) {
        if (!timestamp) return '';
        const date = new Date(timestamp * 1000);
        const now = new Date();
        const diff = now - date;
        const minutes = Math.floor(diff / 60000);
        const hours = Math.floor(diff / 3600000);
        const days = Math.floor(diff / 86400000);

        if (minutes < 1) return '刚刚';
        if (minutes < 60) return minutes + '分钟前';
        if (hours < 24) return hours + '小时前';
        if (days < 7) return days + '天前';
        return date.toLocaleDateString('zh-CN');
    }

    /**
     * 显示提示消息
     */
    function showToast(message, type) {
        // 简单的提示实现，可以后续优化
        const toast = document.createElement('div');
        let bgColor = '#dc3545'; // 默认错误颜色
        if (type === 'success') {
            bgColor = '#28a745';
        } else if (type === 'warning') {
            bgColor = '#ffc107';
        }
        
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            background: ${bgColor};
            color: ${type === 'warning' ? '#000' : 'white'};
            border-radius: 4px;
            z-index: 10000;
            font-size: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            max-width: 400px;
            word-wrap: break-word;
        `;
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s';
            setTimeout(() => toast.remove(), 300);
        }, 2000);
    }

    // 导出到全局
    window.AttachmentUpload = {
        init: initAttachmentUpload
    };
})();

