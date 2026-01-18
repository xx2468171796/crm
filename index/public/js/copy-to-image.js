/**
 * 复制为图片功能 - 完全重做版
 * A4竖版简历风格，只显示填写的内容
 */

async function copyElementAsImage(elementId, title = '') {
    const element = document.getElementById(elementId);
    if (!element) {
        showAlertModal('未找到要复制的内容', 'error');
        return;
    }
    
    try {
        showAlertModal('正在生成图片...', 'info');
        
        // 创建容器
        const container = document.createElement('div');
        container.style.cssText = `
            width: 794px;
            padding: 40px;
            background: #fff;
            font-family: "Microsoft YaHei", sans-serif;
            font-size: 10px;
            line-height: 1.6;
            color: #333;
            box-sizing: border-box;
        `;
        
        // 添加标题 - ANKOTTI 客户信息
        const titleDiv = document.createElement('div');
        titleDiv.style.cssText = `
            border-bottom: 1px solid #ddd;
            padding-bottom: 8px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        `;
        const now = new Date().toLocaleString('zh-CN', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
        titleDiv.innerHTML = `
            <h2 style="margin: 0; font-size: 14px; color: #666; font-weight: normal;">ANKOTTI　客户信息</h2>
            <span style="font-size: 9px; color: #999;">生成时间　${now}</span>
        `;
        container.appendChild(titleDiv);
        
        // 提取数据
        const data = extractFormData(element);
        
        // 渲染内容
        renderContent(container, data);
        
        // 临时添加到页面
        container.style.position = 'absolute';
        container.style.left = '-9999px';
        document.body.appendChild(container);
        
        // 生成图片
        const canvas = await html2canvas(container, {
            scale: 2,
            useCORS: true,
            backgroundColor: '#ffffff',
            logging: false
        });
        
        // 移除临时元素
        document.body.removeChild(container);
        
        // 转换为Blob
        canvas.toBlob(async function(blob) {
            if (!blob) {
                console.error('生成Blob失败');
                showAlertModal('生成图片失败', 'error');
                return;
            }
            
            console.log('Blob生成成功，大小:', blob.size);
            
            try {
                // 尝试使用剪贴板API复制
                if (navigator.clipboard && navigator.clipboard.write) {
                    try {
                        await navigator.clipboard.write([
                            new ClipboardItem({
                                'image/png': blob
                            })
                        ]);
                        
                        console.log('复制到剪贴板成功');
                        showAlertModal('✅ 图片已复制到剪贴板！<br><small>可以直接粘贴到微信、QQ等应用</small>', 'success', null, 3000);
                        return; // 成功后直接返回
                    } catch (clipboardErr) {
                        console.warn('剪贴板API复制失败，尝试降级方案:', clipboardErr);
                    }
                }
                
                // 降级方案：自动下载图片
                const url = canvas.toDataURL('image/png');
                const link = document.createElement('a');
                link.download = `${title || '客户信息'}_${Date.now()}.png`;
                link.href = url;
                link.click();
                
                showAlertModal('✅ 图片已自动下载到本地<br><small>💡 提示：如无法复制到剪贴板，可使用下载的图片</small>', 'success', null, 3000);
                
            } catch (err) {
                console.error('处理图片失败:', err);
                
                // 最终降级方案：下载图片
                const url = canvas.toDataURL('image/png');
                const link = document.createElement('a');
                link.download = `${title || '客户信息'}_${Date.now()}.png`;
                link.href = url;
                link.click();
                
                showAlertModal('⚠️ 图片已自动下载到本地<br><small>建议使用Chrome或Edge浏览器以获得更好体验</small>', 'warning', null, 5000);
            }
        }, 'image/png');
        
    } catch (error) {
        console.error('生成图片失败:', error);
        showAlertModal('生成图片失败: ' + error.message, 'error');
    }
}

/**
 * 提取表单数据 - 按照设计的表格结构
 */
function extractFormData(element) {
    const data = {
        basic: {},      // 基础信息
        fields: []      // 其他字段
    };
    
    // 提取基础信息
    // 客户姓名 - 从top-bar中查找
    const nameInput = document.querySelector('input[name="name"]');
    data.basic.name = nameInput?.value || '';
    
    // 性别和年龄 - 从top-bar中查找
    const genderInput = document.querySelector('input[name="gender"]');
    const ageInput = document.querySelector('input[name="age"]');
    data.basic.gender = genderInput?.value || '';
    data.basic.age = ageInput?.value || '';
    
    // 联系方式
    const contactInput = document.querySelector('input[name="contact"]');
    data.basic.contact = contactInput?.value || '';
    
    // 提取身份 - 从element内部查找
    const identityChecked = element.querySelector('input[name="identity"]:checked');
    if (identityChecked) {
        const identityLabel = identityChecked.closest('label');
        data.basic.identity = identityLabel?.textContent.trim() || '';
    }
    
    // 提取所有字段（排除基础信息字段）
    const excludeLabels = ['客户姓名', '性别', '年龄', '联系方式', '身份'];
    
    element.querySelectorAll('.field-row, .mb-3').forEach(row => {
        const label = row.querySelector('label, .field-label');
        if (!label) return;
        
        const fieldName = label.textContent.trim().replace(/\s*\*\s*$/, ''); // 移除必填标记
        
        // 跳过基础信息字段
        if (excludeLabels.includes(fieldName)) return;
        
        const values = [];
        
        // 提取选中的checkbox/radio
        row.querySelectorAll('input[type="checkbox"]:checked, input[type="radio"]:checked').forEach(input => {
            const inputLabel = input.closest('label');
            if (inputLabel) {
                values.push(inputLabel.textContent.trim());
            }
        });
        
        // 提取文本输入
        row.querySelectorAll('input[type="text"], textarea').forEach(input => {
            if (input.value && input.value.trim()) {
                values.push(input.value.trim());
            }
        });
        
        if (values.length > 0) {
            data.fields.push({ name: fieldName, values: values });
        }
    });
    
    return data;
}

/**
 * 渲染内容 - 表格式排版
 */
function renderContent(container, data) {
    // 创建表格
    const table = document.createElement('table');
    table.style.cssText = `
        width: 100%;
        border-collapse: collapse;
        font-size: 10px;
    `;
    
    // 第一行：客户姓名和身份
    const row1 = document.createElement('tr');
    row1.innerHTML = `
        <td style="border: 1px solid #ddd; padding: 8px; background: #f9f9f9; width: 15%;">客户姓名：</td>
        <td style="border: 1px solid #ddd; padding: 8px; width: 35%;">${data.basic.name}</td>
        <td style="border: 1px solid #ddd; padding: 8px; background: #f9f9f9; width: 15%;">身份：</td>
        <td style="border: 1px solid #ddd; padding: 8px; width: 35%;">${data.basic.identity}</td>
    `;
    table.appendChild(row1);
    
    // 第二行：性别、年龄、联系方式
    const row2 = document.createElement('tr');
    row2.innerHTML = `
        <td style="border: 1px solid #ddd; padding: 8px; background: #f9f9f9;">性别：</td>
        <td style="border: 1px solid #ddd; padding: 8px;">${data.basic.gender}</td>
        <td style="border: 1px solid #ddd; padding: 8px; background: #f9f9f9;">年龄：</td>
        <td style="border: 1px solid #ddd; padding: 8px;">${data.basic.age}</td>
    `;
    table.appendChild(row2);
    
    // 联系方式单独一行
    if (data.basic.contact) {
        const rowContact = document.createElement('tr');
        rowContact.innerHTML = `
            <td style="border: 1px solid #ddd; padding: 8px; background: #f9f9f9;">联系方式：</td>
            <td colspan="3" style="border: 1px solid #ddd; padding: 8px;">${data.basic.contact}</td>
        `;
        table.appendChild(rowContact);
    }
    
    // 其他字段
    data.fields.forEach(field => {
        const row = document.createElement('tr');
        const valueText = field.values.join('　');
        
        row.innerHTML = `
            <td style="border: 1px solid #ddd; padding: 8px; background: #f9f9f9; vertical-align: top;">${field.name}</td>
            <td colspan="3" style="border: 1px solid #ddd; padding: 8px; white-space: pre-wrap;">${valueText}</td>
        `;
        table.appendChild(row);
    });
    
    container.appendChild(table);
    
    // 添加底部签名区
    const footer = document.createElement('div');
    footer.style.cssText = `
        margin-top: 20px;
        text-align: right;
        font-size: 10px;
        color: #666;
    `;
    
    // 获取当前用户名
    const currentUser = window.currentUserName || ''; // 从全局变量获取
    
    footer.innerHTML = `
        <span style="margin-right: 40px;">客户归属人：__________</span>
        <span>员工姓名：${currentUser || '__________'}</span>
    `;
    container.appendChild(footer);
}

/**
 * 复制当前激活的Tab为图片
 */
function copyCurrentTabAsImage() {
    const activeTab = document.querySelector('.tab-content-section.active');
    if (!activeTab) {
        showAlertModal('未找到激活的Tab', 'error');
        return;
    }
    
    const tabId = activeTab.id;
    let title = '客户信息';
    
    switch(tabId) {
        case 'tab-first_contact':
            title = '首通记录';
            break;
        case 'tab-objection':
            title = '异议处理';
            break;
        case 'tab-deal':
            title = '敲定成交';
            break;
        case 'tab-service':
            title = '正式服务';
            break;
        case 'tab-feedback':
            title = '客户回访';
            break;
        case 'tab-files':
            title = '文件管理';
            break;
        case 'tab-evaluation':
            title = '沟通自评';
            break;
    }
    
    const customerName = document.querySelector('input[name="name"]')?.value;
    if (customerName) {
        title = `${customerName} - ${title}`;
    }
    
    copyElementAsImage(tabId, title);
}

console.log('copy-to-image.js loaded successfully');
