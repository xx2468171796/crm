/**
 * 客户看板视图组件
 * 支持看板/表格双视图，动态字段分列，列配置和拖拽排序
 */
const CustomerKanban = (function() {
    // 状态
    let currentView = 'kanban'; // 'kanban' | 'table'
    let statusField = null;     // 当前选中的分组字段配置
    let allFilterFields = [];   // 所有筛选字段
    let customersData = [];     // 客户数据
    let columnConfig = null;    // 表格列配置
    let kanbanContainerId = ''; // 看板容器ID
    
    // 配置键
    const VIEW_PREF_KEY = 'my_customers_view';
    const COLUMN_CONFIG_KEY = 'my_customers_columns';
    const KANBAN_FIELD_KEY = 'my_customers_kanban_field'; // 看板分组字段
    
    // XSS转义
    function escapeHtml(text) {
        if (text == null) return '';
        const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }
    
    // 格式化时间
    function formatTime(timestamp) {
        if (!timestamp) return '-';
        const date = new Date(timestamp * 1000);
        const now = new Date();
        const diff = Math.floor((now - date) / 1000);
        
        if (diff < 60) return '刚刚';
        if (diff < 3600) return Math.floor(diff / 60) + '分钟前';
        if (diff < 86400) return Math.floor(diff / 3600) + '小时前';
        if (diff < 604800) return Math.floor(diff / 86400) + '天前';
        
        return date.toLocaleDateString('zh-CN');
    }
    
    /**
     * 初始化
     */
    async function init(options = {}) {
        const {
            customers = [],
            containerId = 'customerKanbanContainer',
            tableContainerId = 'customerTableContainer',
            onCustomerClick = null,
            onCopyLink = null,
            onDelete = null
        } = options;
        
        customersData = customers;
        kanbanContainerId = containerId;
        
        // 加载状态字段配置
        await loadStatusField();
        
        // 加载用户视图偏好（默认表格视图）
        currentView = localStorage.getItem(VIEW_PREF_KEY) || 'table';
        
        // 加载列配置
        loadColumnConfig();
        
        // 渲染视图切换按钮
        updateViewToggle();
        
        // 渲染分组字段选择器
        renderFieldSelector();
        
        // 控制分组选择器显示状态
        const fieldSelectorWrapper = document.getElementById('kanbanFieldSelectorWrapper');
        if (fieldSelectorWrapper) {
            fieldSelectorWrapper.style.display = currentView === 'kanban' ? 'flex' : 'none';
        }
        
        // 初始化容器显示状态
        const kanbanContainer = document.getElementById(containerId);
        const tableContainer = document.getElementById(tableContainerId);
        
        if (currentView === 'table') {
            if (kanbanContainer) kanbanContainer.classList.add('hidden');
            if (tableContainer) tableContainer.classList.add('active');
        } else {
            if (kanbanContainer) kanbanContainer.classList.remove('hidden');
            if (tableContainer) tableContainer.classList.remove('active');
        }
        
        // 渲染当前视图
        if (currentView === 'kanban') {
            renderKanban(containerId);
        } else {
            renderTable(tableContainerId);
        }
        
        // 保存回调
        CustomerKanban.onCustomerClick = onCustomerClick;
        CustomerKanban.onCopyLink = onCopyLink;
        CustomerKanban.onDelete = onDelete;
    }
    
    /**
     * 加载客户状态字段配置（从后台动态字段加载）
     */
    async function loadStatusField() {
        try {
            const response = await fetch('/api/customer_filter_fields.php?action=list');
            const result = await response.json();
            
            if (result.success && result.data) {
                allFilterFields = result.data;
                
                // 读取用户保存的分组字段偏好
                const savedFieldId = localStorage.getItem(KANBAN_FIELD_KEY);
                
                if (savedFieldId) {
                    statusField = result.data.find(f => f.id == savedFieldId);
                }
                
                // 如果没有保存的偏好或找不到，使用第一个字段
                if (!statusField) {
                    statusField = result.data[0];
                }
                
                if (!statusField) {
                    console.warn('[CUSTOMER_KANBAN] 未找到客户状态字段，使用默认分组');
                }
            }
        } catch (error) {
            console.error('[CUSTOMER_KANBAN] 加载状态字段失败:', error);
        }
    }
    
    /**
     * 切换看板分组字段
     */
    function switchKanbanField(fieldId) {
        const field = allFilterFields.find(f => f.id == fieldId);
        if (field) {
            statusField = field;
            localStorage.setItem(KANBAN_FIELD_KEY, fieldId);
            renderKanban(kanbanContainerId);
            renderFieldSelector();
        }
    }
    
    /**
     * 渲染分组字段选择器
     */
    function renderFieldSelector() {
        const container = document.getElementById('kanbanFieldSelector');
        if (!container || allFilterFields.length === 0) return;
        
        let html = '<select class="form-select form-select-sm" style="width: auto;" onchange="CustomerKanban.switchKanbanField(this.value)">';
        allFilterFields.forEach(field => {
            const selected = statusField && statusField.id == field.id ? 'selected' : '';
            html += `<option value="${field.id}" ${selected}>${escapeHtml(field.field_label)}</option>`;
        });
        html += '</select>';
        
        container.innerHTML = '<label class="me-2" style="font-size: 13px; color: #64748b;">分组:</label>' + html;
    }
    
    /**
     * 加载列配置
     */
    function loadColumnConfig() {
        try {
            const saved = localStorage.getItem(COLUMN_CONFIG_KEY);
            if (saved) {
                columnConfig = JSON.parse(saved);
            }
        } catch (e) {
            columnConfig = null;
        }
        
        if (!columnConfig) {
            columnConfig = getDefaultColumnConfig();
        }
    }
    
    /**
     * 获取默认列配置
     */
    function getDefaultColumnConfig() {
        return {
            visible: ['customer_code', 'name', 'customer_group', 'file_count', 'update_time', 'actions'],
            order: ['customer_code', 'custom_id', 'name', 'customer_group', 'file_count', 'update_time', 'create_time', 'actions']
        };
    }
    
    /**
     * 保存列配置
     */
    function saveColumnConfig() {
        localStorage.setItem(COLUMN_CONFIG_KEY, JSON.stringify(columnConfig));
    }
    
    /**
     * 切换视图
     */
    function switchView(view) {
        if (view === currentView) return;
        
        currentView = view;
        localStorage.setItem(VIEW_PREF_KEY, view);
        
        updateViewToggle();
        
        const kanbanContainer = document.getElementById('customerKanbanContainer');
        const tableContainer = document.getElementById('customerTableContainer');
        const fieldSelectorWrapper = document.getElementById('kanbanFieldSelectorWrapper');
        
        if (view === 'kanban') {
            if (kanbanContainer) kanbanContainer.classList.remove('hidden');
            if (tableContainer) tableContainer.classList.remove('active');
            if (fieldSelectorWrapper) fieldSelectorWrapper.style.display = 'flex';
            renderKanban('customerKanbanContainer');
        } else {
            if (kanbanContainer) kanbanContainer.classList.add('hidden');
            if (tableContainer) tableContainer.classList.add('active');
            if (fieldSelectorWrapper) fieldSelectorWrapper.style.display = 'none';
            renderTable('customerTableContainer');
        }
    }
    
    /**
     * 更新视图切换按钮状态
     */
    function updateViewToggle() {
        const btnKanban = document.getElementById('btnKanbanView');
        const btnTable = document.getElementById('btnTableView');
        
        if (btnKanban) {
            btnKanban.classList.toggle('active', currentView === 'kanban');
        }
        if (btnTable) {
            btnTable.classList.toggle('active', currentView === 'table');
        }
    }
    
    /**
     * 渲染看板视图
     */
    function renderKanban(containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;
        
        // 如果没有状态字段，显示所有客户在一列
        if (!statusField || !statusField.options || statusField.options.length === 0) {
            container.innerHTML = `
                <div class="kanban-column has-cards" style="min-width: 100%; max-width: 100%;">
                    <div class="kanban-header">
                        <h3>
                            <span class="status-dot" style="background: #64748b;"></span>
                            所有客户
                            <span class="status-count">${customersData.length}</span>
                        </h3>
                    </div>
                    <div class="kanban-cards" id="cards-all"></div>
                </div>
            `;
            
            const cardsContainer = document.getElementById('cards-all');
            customersData.forEach(customer => {
                cardsContainer.appendChild(createCustomerCard(customer));
            });
            return;
        }
        
        // 按状态分组
        const groupedCustomers = {};
        statusField.options.forEach(opt => {
            groupedCustomers[opt.id] = [];
        });
        groupedCustomers['未分类'] = [];
        
        customersData.forEach(customer => {
            const statusValue = customer.filter_values?.[statusField.id];
            if (statusValue && groupedCustomers[statusValue]) {
                groupedCustomers[statusValue].push(customer);
            } else {
                groupedCustomers['未分类'].push(customer);
            }
        });
        
        // 渲染列
        let html = '';
        statusField.options.forEach(opt => {
            const customers = groupedCustomers[opt.id] || [];
            const hasCards = customers.length > 0;
            
            html += `
                <div class="kanban-column ${hasCards ? 'has-cards' : ''}" data-status-id="${opt.id}">
                    <div class="kanban-header">
                        <h3>
                            <span class="status-dot" style="background: ${escapeHtml(opt.color || '#64748b')};"></span>
                            ${escapeHtml(opt.option_label)}
                            <span class="status-count">${customers.length}</span>
                        </h3>
                    </div>
                    <div class="kanban-cards" id="cards-${opt.id}"></div>
                </div>
            `;
        });
        
        // 未分类列
        const uncategorized = groupedCustomers['未分类'] || [];
        if (uncategorized.length > 0) {
            html += `
                <div class="kanban-column has-cards" data-status-id="uncategorized">
                    <div class="kanban-header">
                        <h3>
                            <span class="status-dot" style="background: #94a3b8;"></span>
                            未分类
                            <span class="status-count">${uncategorized.length}</span>
                        </h3>
                    </div>
                    <div class="kanban-cards" id="cards-uncategorized"></div>
                </div>
            `;
        }
        
        container.innerHTML = html;
        
        // 渲染卡片
        statusField.options.forEach(opt => {
            const cardsContainer = document.getElementById('cards-' + opt.id);
            if (cardsContainer) {
                (groupedCustomers[opt.id] || []).forEach(customer => {
                    cardsContainer.appendChild(createCustomerCard(customer));
                });
            }
        });
        
        // 渲染未分类卡片
        const uncategorizedContainer = document.getElementById('cards-uncategorized');
        if (uncategorizedContainer) {
            uncategorized.forEach(customer => {
                uncategorizedContainer.appendChild(createCustomerCard(customer));
            });
        }
    }
    
    /**
     * 创建客户卡片
     */
    function createCustomerCard(customer) {
        const card = document.createElement('div');
        card.className = 'customer-card';
        card.dataset.id = customer.id;
        card.onclick = function(e) {
            if (!e.target.closest('.card-actions')) {
                if (CustomerKanban.onCustomerClick) {
                    CustomerKanban.onCustomerClick(customer.id, customer.name);
                }
            }
        };
        
        // 构建标签HTML
        let tagsHtml = '';
        if (customer.filter_values_display) {
            Object.values(customer.filter_values_display).forEach(tag => {
                if (tag.label && tag.color) {
                    tagsHtml += `<span class="card-tag" style="background: ${escapeHtml(tag.color)}">${escapeHtml(tag.label)}</span>`;
                }
            });
        }
        
        // 文件数量
        const fileCount = (customer.customer_file_count || 0) + (customer.company_file_count || 0);
        
        // 链接按钮
        const hasLink = customer.link_enabled !== null;
        
        card.innerHTML = `
            <div class="card-header">
                <strong class="customer-name">${escapeHtml(customer.name)}</strong>
                <code class="customer-code">${escapeHtml(customer.customer_code)}</code>
            </div>
            ${tagsHtml ? `<div class="card-tags">${tagsHtml}</div>` : ''}
            <div class="card-meta">
                <span><i class="bi bi-folder"></i> ${fileCount}个文件</span>
                <span><i class="bi bi-clock"></i> ${formatTime(customer.update_time)}</span>
            </div>
            <div class="card-actions" onclick="event.stopPropagation()">
                ${hasLink ? `
                    <button class="btn-copy" onclick="CustomerKanban.copyLink('${escapeHtml(customer.customer_code)}')" title="复制链接">
                        <i class="bi bi-link-45deg"></i> 链接
                    </button>
                ` : ''}
                <button class="btn-delete" onclick="CustomerKanban.deleteCustomer(${customer.id}, '${escapeHtml(customer.name)}')" title="删除">
                    <i class="bi bi-trash"></i> 删除
                </button>
            </div>
        `;
        
        return card;
    }
    
    /**
     * 渲染表格视图
     */
    function renderTable(containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;
        
        // 表格视图使用原有PHP渲染的表格，只需显示/隐藏
        container.classList.add('active');
    }
    
    /**
     * 复制链接
     */
    function copyLink(customerCode) {
        if (CustomerKanban.onCopyLink) {
            CustomerKanban.onCopyLink(customerCode);
        }
    }
    
    /**
     * 删除客户
     */
    function deleteCustomer(customerId, customerName) {
        if (CustomerKanban.onDelete) {
            CustomerKanban.onDelete(customerId, customerName);
        }
    }
    
    /**
     * 更新客户数据
     */
    function updateData(customers) {
        customersData = customers;
        if (currentView === 'kanban') {
            renderKanban('customerKanbanContainer');
        }
    }
    
    /**
     * 获取当前视图
     */
    function getCurrentView() {
        return currentView;
    }
    
    return {
        init,
        switchView,
        switchKanbanField,
        updateData,
        copyLink,
        deleteCustomer,
        getCurrentView,
        loadStatusField,
        onCustomerClick: null,
        onCopyLink: null,
        onDelete: null
    };
})();
