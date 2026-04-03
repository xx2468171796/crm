(function () {
    const BOOT = window.OKR_BOOTSTRAP;
    if (!BOOT) {
        return;
    }

    const state = {
        cycles: [],
        currentCycleId: null,
        containers: [],
        containerDetails: {},
        allOkrs: [], // 全部OKR数据
        allOkrDetails: {}, // 全部OKR详情
        tasks: [],
        allTasks: [],
        taskFilters: {
            filter: 'my',
            status: ''
        },
        allTaskFilters: {
            status: ''
        },
        allOkrFilters: {
            type: '',
            status: '',
            owner: ''
        },
        view: 'okrs',
        mobileView: 'okrs',
        taskViewMode: 'list', // 'list' | 'calendar' | 'recent'
        calendarDate: new Date(), // 日历当前显示的月份
        recentBaseDate: new Date(), // 近期任务视图的基准日期
        recentSelectedDate: new Date(), // 近期任务视图选中的日期
        krOptions: [],
        relationFilter: 'all',
        relationShowKr: true,
        relationZoom: 1,
        relationPanX: 0,
        relationPanY: 0,
        relationDragging: false,
        pendingComments: { type: null, id: null },
        pendingAlignments: [],
        editingContainerId: null,
        editingObjectiveId: null,
        editingTaskId: null
    };

    const refs = {};

    document.addEventListener('DOMContentLoaded', init);

    async function init() {
        cacheDom();
        bindEvents();
        ensureKrRow();
        await hydrate();
        // 初始化页面头部显示
        updatePageHeader(state.view);
    }

    function cacheDom() {
        refs.body = document.body;
        refs.toast = document.getElementById('okrToast');
        refs.containerList = document.getElementById('okrContainerList');
        refs.okrEmptyState = document.getElementById('okrEmptyState');
        refs.allOkrList = document.getElementById('allOkrList');
        refs.allOkrEmptyState = document.getElementById('allOkrEmptyState');
        refs.allOkrOwnerFilter = document.getElementById('allOkrOwnerFilter');
        refs.taskTable = document.getElementById('okrTaskTable');
        refs.taskEmpty = document.getElementById('taskEmptyState');
        refs.allTaskTable = document.getElementById('allTaskTable');
        refs.allTaskEmpty = document.getElementById('allTaskEmptyState');
        refs.taskViewPanels = document.querySelectorAll('[data-task-panel]');
        refs.taskViewToggleBtns = document.querySelectorAll('[data-task-view-mode]');
        refs.calendarDays = document.getElementById('calendarDays');
        refs.calendarMonthTitle = document.getElementById('calendarMonthTitle');
        refs.calendarPrevMonth = document.getElementById('calendarPrevMonth');
        refs.calendarNextMonth = document.getElementById('calendarNextMonth');
        refs.calendarToday = document.getElementById('calendarToday');
        // 近期任务视图引用
        refs.recentDateStrip = document.getElementById('recentDateStrip');
        refs.recentDateLabel = document.getElementById('recentDateLabel');
        refs.recentPrevWeek = document.getElementById('recentPrevWeek');
        refs.recentNextWeek = document.getElementById('recentNextWeek');
        refs.recentToday = document.getElementById('recentToday');
        refs.recentTaskList = document.getElementById('recentTaskList');
        refs.recentQuickAddInput = document.getElementById('recentQuickAddInput');
        refs.relationCanvas = document.getElementById('okrRelationCanvas');
        refs.relationEmpty = document.getElementById('relationEmptyState');
        refs.relationCanvasWrapper = document.getElementById('relationCanvasWrapper');
        refs.relationCanvasInner = document.getElementById('relationCanvasInner');
        refs.zoomLevel = document.getElementById('zoomLevel');
        refs.desktopViews = document.querySelectorAll('.view');
        refs.sidebarItems = document.querySelectorAll('[data-view-target]');
        refs.mobileViews = document.querySelectorAll('.mobile-view');
        refs.mobileNavButtons = document.querySelectorAll('[data-mobile-nav]');
        refs.mobileOkrList = document.getElementById('mobileOkrList');
        refs.mobileTaskList = document.getElementById('mobileTaskList');
        refs.mobileTaskListStandalone = document.getElementById('mobileTaskListStandalone');
        refs.mobileRelation = document.getElementById('mobileRelationCanvas');
        refs.mobileSummaryProgress = document.getElementById('mobileProgressTotal');
        refs.mobileSummaryTasks = document.getElementById('mobileTaskMetric');
        refs.mobileCycleRemain = document.getElementById('mobileCycleRemain');
        refs.cycleName = document.getElementById('okrCycleName');
        refs.cycleRange = document.getElementById('okrCycleRange');
        refs.cycleRemain = document.getElementById('okrCycleRemain');
        refs.mobileCycleName = document.getElementById('mobileCycleName');
        refs.krTemplate = document.getElementById('okrKrTemplate');
        refs.krList = document.getElementById('okrKrList');
        refs.taskRelationSelect = document.getElementById('okrTaskRelationSelect');
        refs.modals = {
            cycle: document.getElementById('okrModalCycle'),
            cycleSelect: document.getElementById('okrModalCycleSelect'),
            okr: document.getElementById('okrModalCreate'),
            task: document.getElementById('okrModalTask'),
            progress: document.getElementById('okrModalProgress'),
            comment: document.getElementById('okrModalComment'),
            align: document.getElementById('okrModalAlign'),
            krSettings: document.getElementById('okrModalKrSettings'),
            userSelect: document.getElementById('okrModalUserSelect'),
            taskSelect: document.getElementById('okrModalTaskSelect')
        };
        refs.forms = {
            cycle: document.getElementById('okrCycleForm'),
            container: document.getElementById('okrContainerForm'),
            task: document.getElementById('okrTaskForm'),
            comment: document.getElementById('okrCommentForm')
        };
        refs.progressList = document.getElementById('okrProgressList');
        refs.commentList = document.getElementById('okrCommentList');
        // 新建OKR弹窗相关元素
        refs.okrCreate = {
            cycleName: document.getElementById('okrCreateCycleName'),
            userName: document.getElementById('okrCreateUserName'),
            alignList: document.getElementById('okrAlignList'),
            oBadge: document.getElementById('okrOBadge')
        };
        refs.alignListBody = document.getElementById('okrAlignListBody');
        refs.userSelectList = document.getElementById('okrUserSelectList');
        refs.cycleList = document.getElementById('okrCycleList');
        // 页面头部元素
        refs.pageTitle = document.getElementById('okrPageTitle');
        refs.pageMeta = document.getElementById('okrPageMeta');
        refs.pageActions = document.getElementById('okrPageActions');
        refs.progressBtn = document.getElementById('okrProgressBtn');
    }

    function bindEvents() {
        document.addEventListener('click', handleClick);
        document.addEventListener('submit', handleSubmit);
        
        // 关系视图画布拖拽和缩放
        initRelationCanvasEvents();
        
        // 日历导航事件
        if (refs.calendarPrevMonth) {
            refs.calendarPrevMonth.addEventListener('click', () => {
                state.calendarDate.setMonth(state.calendarDate.getMonth() - 1);
                renderCalendar();
            });
        }
        if (refs.calendarNextMonth) {
            refs.calendarNextMonth.addEventListener('click', () => {
                state.calendarDate.setMonth(state.calendarDate.getMonth() + 1);
                renderCalendar();
            });
        }
        if (refs.calendarToday) {
            refs.calendarToday.addEventListener('click', () => {
                state.calendarDate = new Date();
                renderCalendar();
            });
        }

        // 近期任务视图导航事件
        if (refs.recentPrevWeek) {
            refs.recentPrevWeek.addEventListener('click', () => {
                state.recentBaseDate.setDate(state.recentBaseDate.getDate() - 7);
                renderRecentTasks();
            });
        }
        if (refs.recentNextWeek) {
            refs.recentNextWeek.addEventListener('click', () => {
                state.recentBaseDate.setDate(state.recentBaseDate.getDate() + 7);
                renderRecentTasks();
            });
        }
        if (refs.recentToday) {
            refs.recentToday.addEventListener('click', () => {
                state.recentBaseDate = new Date();
                state.recentSelectedDate = new Date();
                renderRecentTasks();
            });
        }
        // 近期任务快速添加
        if (refs.recentQuickAddInput) {
            refs.recentQuickAddInput.addEventListener('keydown', async (e) => {
                if (e.key === 'Enter' && refs.recentQuickAddInput.value.trim()) {
                    const title = refs.recentQuickAddInput.value.trim();
                    const dueDate = state.recentSelectedDate.toISOString().split('T')[0];
                    try {
                        await apiFetch('okr_task.php', {
                            method: 'POST',
                            body: { action: 'create', title, due_date: dueDate }
                        });
                        refs.recentQuickAddInput.value = '';
                        await loadTasks();
                        showToast('任务创建成功', 'success');
                    } catch (err) {
                        showToast(err.message || '创建失败', 'error');
                    }
                }
            });
        }
        // 近期任务日期条点击事件委托
        if (refs.recentDateStrip) {
            refs.recentDateStrip.addEventListener('click', (e) => {
                const dateItem = e.target.closest('.recent-date-item');
                if (dateItem && dateItem.dataset.date) {
                    state.recentSelectedDate = new Date(dateItem.dataset.date);
                    renderRecentTasks();
                }
            });
        }
        // 近期任务列表点击事件委托
        if (refs.recentTaskList) {
            refs.recentTaskList.addEventListener('click', async (e) => {
                const taskItem = e.target.closest('.recent-task-item');
                if (!taskItem) return;

                const taskId = taskItem.dataset.taskId;
                const checkbox = e.target.closest('.recent-task-checkbox');

                if (checkbox) {
                    // 切换任务状态
                    const task = state.tasks.find(t => t.id == taskId);
                    if (task) {
                        const newStatus = task.status === 'completed' ? 'pending' : 'completed';
                        try {
                            await apiFetch('okr_task.php', {
                                method: 'POST',
                                body: { action: 'update', id: taskId, status: newStatus }
                            });
                            await loadTasks();
                            showToast('状态已更新', 'success');
                        } catch (err) {
                            showToast(err.message || '更新失败', 'error');
                        }
                    }
                } else {
                    // 打开任务详情
                    openEditTaskModal(taskId);
                }
            });
        }

        // 新建OKR弹窗 - 层级选择
        document.querySelectorAll('.okr-level-tabs .level-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                if (tab.disabled) return;
                document.querySelectorAll('.okr-level-tabs .level-tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                const levelInput = document.querySelector('input[name="level"]');
                if (levelInput) levelInput.value = tab.dataset.level;
            });
        });

        // 用户选择列表点击
        if (refs.userSelectList) {
            refs.userSelectList.addEventListener('click', (e) => {
                const item = e.target.closest('.user-select-item');
                if (!item) return;
                
                const userId = item.dataset.userId;
                const userName = item.dataset.userName;
                
                // 更新选中状态
                refs.userSelectList.querySelectorAll('.user-select-item').forEach(i => {
                    i.classList.remove('selected');
                    i.querySelector('.check-icon').style.display = 'none';
                });
                item.classList.add('selected');
                item.querySelector('.check-icon').style.display = 'inline';
                
                // 更新表单
                const userIdInput = document.querySelector('input[name="user_id"]');
                if (userIdInput) userIdInput.value = userId;
                if (refs.okrCreate.userName) refs.okrCreate.userName.textContent = userName;
                
                // 关闭弹窗
                closeModal(refs.modals.userSelect);
            });
        }

        // 对齐列表点击
        if (refs.alignListBody) {
            refs.alignListBody.addEventListener('click', (e) => {
                const checkbox = e.target.closest('.align-okr-checkbox');
                if (!checkbox) return;
                
                checkbox.classList.toggle('checked');
                if (checkbox.classList.contains('checked')) {
                    checkbox.innerHTML = '✓';
                } else {
                    checkbox.innerHTML = '';
                }
                updateAlignCount();
            });
        }

        // KR信心指数滑块
        const krConfidenceSlider = document.getElementById('krSettingsConfidence');
        if (krConfidenceSlider) {
            krConfidenceSlider.addEventListener('input', (e) => {
                const value = e.target.value;
                document.getElementById('krConfidenceValue').textContent = value;
                // 更新滑块背景
                const percent = ((value - 1) / 9) * 100;
                e.target.style.background = `linear-gradient(to right, var(--primary-color) ${percent}%, var(--divider-color) ${percent}%)`;
            });
        }

        // 全部OKR负责人筛选输入框
        if (refs.allOkrOwnerFilter) {
            let ownerFilterTimer;
            refs.allOkrOwnerFilter.addEventListener('input', (e) => {
                clearTimeout(ownerFilterTimer);
                ownerFilterTimer = setTimeout(() => {
                    state.allOkrFilters.owner = e.target.value.trim();
                    renderAllOkrs();
                }, 300);
            });
        }
    }

    function initRelationCanvasEvents() {
        const wrapper = document.getElementById('relationCanvasWrapper');
        if (!wrapper) return;

        let startX, startY;
        let dragStartTime = 0;
        let hasMoved = false;

        // 鼠标拖拽
        wrapper.addEventListener('mousedown', (e) => {
            // 忽略缩放面板和工具栏上的点击
            if (e.target.closest('.zoom-panel') || e.target.closest('.relation-toolbar')) return;
            
            state.relationDragging = true;
            hasMoved = false;
            dragStartTime = Date.now();
            startX = e.clientX - state.relationPanX;
            startY = e.clientY - state.relationPanY;
            wrapper.classList.add('dragging');
            e.preventDefault(); // 防止文本选择
        });

        document.addEventListener('mousemove', (e) => {
            if (!state.relationDragging) return;
            
            const dx = e.clientX - startX - state.relationPanX;
            const dy = e.clientY - startY - state.relationPanY;
            
            // 检测是否有明显移动
            if (Math.abs(dx) > 3 || Math.abs(dy) > 3) {
                hasMoved = true;
            }
            
            state.relationPanX = e.clientX - startX;
            state.relationPanY = e.clientY - startY;
            updateRelationTransform();
        });

        document.addEventListener('mouseup', (e) => {
            if (state.relationDragging) {
                state.relationDragging = false;
                wrapper.classList.remove('dragging');
                
                // 如果拖拽时间短且没有移动，视为点击
                const dragDuration = Date.now() - dragStartTime;
                if (dragDuration < 200 && !hasMoved) {
                    // 允许点击卡片
                    const card = e.target.closest('.relation-card');
                    if (card) {
                        const objId = card.dataset.objectiveId;
                        if (objId) {
                            // 触发查看目标详情
                            console.log('点击目标卡片:', objId);
                        }
                    }
                }
            }
        });

        // 触摸拖拽支持
        let touchStartX, touchStartY;
        wrapper.addEventListener('touchstart', (e) => {
            if (e.target.closest('.zoom-panel') || e.target.closest('.relation-toolbar')) return;
            if (e.touches.length === 1) {
                state.relationDragging = true;
                touchStartX = e.touches[0].clientX - state.relationPanX;
                touchStartY = e.touches[0].clientY - state.relationPanY;
            }
        }, { passive: true });

        wrapper.addEventListener('touchmove', (e) => {
            if (!state.relationDragging || e.touches.length !== 1) return;
            state.relationPanX = e.touches[0].clientX - touchStartX;
            state.relationPanY = e.touches[0].clientY - touchStartY;
            updateRelationTransform();
        }, { passive: true });

        wrapper.addEventListener('touchend', () => {
            state.relationDragging = false;
        }, { passive: true });

        // 鼠标滚轮缩放
        wrapper.addEventListener('wheel', (e) => {
            e.preventDefault();
            const scaleStep = 0.15;
            const delta = e.deltaY > 0 ? -scaleStep : scaleStep;
            const newScale = Math.max(0.25, Math.min(2.5, state.relationZoom + delta));
            
            // 以鼠标位置为中心缩放
            const rect = wrapper.getBoundingClientRect();
            const mouseX = e.clientX - rect.left;
            const mouseY = e.clientY - rect.top;
            
            const scaleDiff = newScale - state.relationZoom;
            state.relationPanX -= mouseX * scaleDiff / state.relationZoom;
            state.relationPanY -= mouseY * scaleDiff / state.relationZoom;
            state.relationZoom = newScale;
            
            updateRelationTransform();
        }, { passive: false });

        // 键盘快捷键
        document.addEventListener('keydown', (e) => {
            // 检查是否在关系视图
            const relationView = document.querySelector('.view[data-view="relations"].active');
            if (!relationView) return;

            if (e.ctrlKey || e.metaKey) {
                if (e.key === '=' || e.key === '+') {
                    e.preventDefault();
                    state.relationZoom = Math.min(2.5, state.relationZoom + 0.15);
                    updateRelationTransform();
                } else if (e.key === '-') {
                    e.preventDefault();
                    state.relationZoom = Math.max(0.25, state.relationZoom - 0.15);
                    updateRelationTransform();
                } else if (e.key === '0') {
                    e.preventDefault();
                    state.relationZoom = 1;
                    state.relationPanX = 0;
                    state.relationPanY = 0;
                    updateRelationTransform();
                }
            }
        });
    }

    async function hydrate() {
        await loadCycles();
        await Promise.all([loadContainers(), loadTasks(), loadAllTasks()]);
        renderAll();
    }

    function handleClick(event) {
        const actionEl = event.target.closest('[data-action]');
        if (actionEl) {
            const action = actionEl.dataset.action;
            switch (action) {
                case 'open-cycle-modal':
                    openCycleSelectModal();
                    return;
                case 'open-cycle-manage-modal':
                    openCycleModal();
                    return;
                case 'confirm-cycle-select':
                    confirmCycleSelect();
                    return;
                case 'select-cycle-quick':
                    const cycleId = parseInt(actionEl.dataset.cycleId, 10);
                    selectCycleFromQuick(cycleId);
                    return;
                case 'cycle-prev':
                    prevCycle();
                    return;
                case 'cycle-next':
                    nextCycle();
                    return;
                case 'close-modal':
                    closeModal(actionEl.closest('.okr-modal'));
                    return;
                case 'open-create-okr':
                    openCreateOkrModal();
                    return;
                case 'open-create-task':
                    openCreateTaskModal();
                    return;
                case 'add-kr-row':
                    addKrRow();
                    return;
                case 'remove-kr-row':
                    removeKrRow(actionEl.closest('[data-kr-row]'));
                    return;
                case 'submit-create-okr':
                    createOkr();
                    return;
                case 'open-align-modal':
                    openAlignModal();
                    return;
                case 'confirm-align':
                    confirmAlign();
                    return;
                case 'open-user-select':
                    openUserSelectModal();
                    return;
                case 'open-kr-settings':
                    openKrSettingsModal(actionEl.closest('[data-kr-row]'));
                    return;
                case 'save-kr-settings':
                    saveKrSettings();
                    return;
                case 'kr-link-tasks':
                    openTaskSelectModal(actionEl.closest('[data-kr-row]'));
                    return;
                case 'confirm-task-select':
                    confirmTaskSelect();
                    return;
                case 'create-task-for-kr':
                    createTaskForKr();
                    return;
                case 'open-okr-more-settings':
                    showToast('更多设置功能开发中', 'info');
                    return;
                case 'open-template-center':
                    showToast('模板中心功能开发中', 'info');
                    return;
                case 'submit-task':
                    createTask();
                    return;
                case 'submit-progress':
                    submitProgressUpdates();
                    return;
                case 'submit-comment':
                    submitComment();
                    return;
                case 'refresh-data':
                    hydrate();
                    return;
                case 'open-progress-modal':
                    openProgressModal();
                    return;
                case 'cycle-prev':
                    shiftCycle(-1);
                    return;
                case 'cycle-next':
                    shiftCycle(1);
                    return;
                case 'open-comment':
                    openCommentModal(actionEl.dataset);
                    return;
                case 'save-cycle':
                    if (refs.forms.cycle) {
                        saveCycle(new FormData(refs.forms.cycle));
                    }
                    return;
                case 'select-cycle':
                    selectCycle(actionEl.dataset.cycleId);
                    return;
                case 'delete-cycle':
                    deleteCycle(actionEl.dataset.cycleId);
                    return;
                case 'edit-cycle':
                    editCycle(actionEl.dataset.cycleId);
                    return;
                case 'new-cycle':
                    resetCycleForm();
                    return;
                case 'cancel-cycle-form':
                    resetCycleForm();
                    return;
                case 'edit-okr':
                    editOkr(actionEl.dataset.containerId);
                    return;
                case 'delete-okr':
                    deleteOkr(actionEl.dataset.containerId);
                    return;
                case 'edit-task':
                    editTask(actionEl.dataset.taskId);
                    return;
                case 'delete-task':
                    deleteTask(actionEl.dataset.taskId);
                    return;
                default:
                    break;
            }
        }

        const viewBtn = event.target.closest('[data-view-target]');
        if (viewBtn) {
            switchView(viewBtn.dataset.viewTarget);
        }

        const mobileNav = event.target.closest('[data-mobile-nav]');
        if (mobileNav) {
            switchMobileView(mobileNav.dataset.mobileNav);
        }

        const taskFilter = event.target.closest('[data-task-filter]');
        if (taskFilter) {
            updateTaskFilter(taskFilter.dataset.taskFilter, taskFilter.dataset.value || '');
        }

        const allTaskFilter = event.target.closest('[data-all-task-filter]');
        if (allTaskFilter) {
            updateAllTaskFilter(allTaskFilter.dataset.allTaskFilter, allTaskFilter.dataset.value || '');
        }

        const allOkrFilter = event.target.closest('[data-all-okr-filter]');
        if (allOkrFilter) {
            updateAllOkrFilter(allOkrFilter.dataset.allOkrFilter, allOkrFilter.dataset.value || '');
        }

        // 任务视图模式切换（列表/日历）
        const taskViewModeBtn = event.target.closest('[data-task-view-mode]');
        if (taskViewModeBtn) {
            switchTaskViewMode(taskViewModeBtn.dataset.taskViewMode);
        }

        // 日历中的任务点击
        const calendarTaskItem = event.target.closest('.calendar-task-item');
        if (calendarTaskItem) {
            const taskId = calendarTaskItem.dataset.taskId;
            if (taskId) {
                openEditTask(parseInt(taskId));
            }
        }

        const relationFilter = event.target.closest('[data-relation-filter]');
        if (relationFilter) {
            const filterValue = relationFilter.dataset.relationFilter;
            if (filterValue === 'show-kr') {
                state.relationShowKr = !state.relationShowKr;
                relationFilter.classList.toggle('active', state.relationShowKr);
            } else {
                state.relationFilter = filterValue;
                document.querySelectorAll('[data-relation-filter]').forEach(btn => {
                    if (btn.dataset.relationFilter !== 'show-kr') {
                        btn.classList.toggle('active', btn === relationFilter);
                    }
                });
            }
            renderRelations();
        }

        const relationZoom = event.target.closest('[data-relation-zoom]');
        if (relationZoom) {
            const action = relationZoom.dataset.relationZoom;
            if (action === 'in') {
                state.relationZoom = Math.min(2.5, state.relationZoom + 0.15);
            } else if (action === 'out') {
                state.relationZoom = Math.max(0.25, state.relationZoom - 0.15);
            } else if (action === 'fit') {
                state.relationZoom = 0.7;
                state.relationPanX = 0;
                state.relationPanY = 0;
            } else {
                state.relationZoom = 1;
                state.relationPanX = 0;
                state.relationPanY = 0;
            }
            updateRelationTransform();
        }
    }

    function handleSubmit(event) {
        if (event.target === refs.forms.cycle) {
            event.preventDefault();
            saveCycle(new FormData(refs.forms.cycle));
        } else if (event.target === refs.forms.comment) {
            event.preventDefault();
            submitComment();
        }
    }

    function switchView(view) {
        state.view = view;
        refs.desktopViews.forEach(section => {
            section.classList.toggle('active', section.dataset.view === view);
        });
        refs.sidebarItems.forEach(item => {
            item.classList.toggle('active', item.dataset.viewTarget === view);
        });
        
        // 根据视图更新页面头部
        updatePageHeader(view);
        
        // 切换到全部OKR视图时加载数据
        if (view === 'all-okrs') {
            loadAllOkrs();
        }
    }

    function updatePageHeader(view) {
        const pageHeader = document.getElementById('okrPageHeader');
        if (!pageHeader || !refs.pageTitle || !refs.pageMeta || !refs.pageActions) return;
        
        // "我的任务"视图有自己的header，隐藏主header
        if (view === 'tasks') {
            pageHeader.style.display = 'none';
            return;
        } else {
            pageHeader.style.display = '';
        }
        
        // 获取所有按钮引用
        const progressBtn = refs.progressBtn;
        const createOkrBtn = refs.pageActions.querySelector('[data-action="open-create-okr"]');
        const createTaskBtn = refs.pageActions.querySelector('[data-action="open-create-task"]');
        const cycleBtn = refs.pageActions.querySelector('[data-action="open-cycle-modal"]');
        let relationSearchBtn = refs.pageActions.querySelector('#relationSearchBtn');
        
        // 先隐藏所有按钮
        if (progressBtn) progressBtn.style.display = 'none';
        if (createOkrBtn) createOkrBtn.style.display = 'none';
        if (createTaskBtn) createTaskBtn.style.display = 'none';
        if (cycleBtn) cycleBtn.style.display = 'none';
        if (relationSearchBtn) relationSearchBtn.style.display = 'none';
        
        // 根据视图显示相应的按钮
        if (view === 'all-okrs') {
            refs.pageTitle.textContent = '全部OKR';
            refs.pageMeta.textContent = '查看所有团队成员的OKR进度与对齐情况';
            if (cycleBtn) cycleBtn.style.display = '';
            if (createOkrBtn) createOkrBtn.style.display = '';
        } else if (view === 'okrs') {
            refs.pageTitle.textContent = '我的 OKR';
            refs.pageMeta.textContent = '对齐策略、KR 进度与任务状态实时同步';
            if (cycleBtn) cycleBtn.style.display = '';
            if (progressBtn) progressBtn.style.display = '';
            if (createOkrBtn) createOkrBtn.style.display = '';
        } else if (view === 'all-tasks') {
            refs.pageTitle.textContent = '全部任务';
            refs.pageMeta.textContent = '查看所有团队成员的任务进度与分配情况';
            if (createTaskBtn) createTaskBtn.style.display = '';
        } else if (view === 'relations') {
            refs.pageTitle.textContent = '关系视图';
            refs.pageMeta.textContent = '可视化OKR之间的对齐关系与依赖';
            // 关系视图需要搜索按钮，如果不存在则创建
            if (!relationSearchBtn) {
                relationSearchBtn = document.createElement('button');
                relationSearchBtn.className = 'okr-btn secondary';
                relationSearchBtn.id = 'relationSearchBtn';
                relationSearchBtn.innerHTML = `
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
                    </svg>
                    搜索
                `;
                refs.pageActions.appendChild(relationSearchBtn);
            }
            relationSearchBtn.style.display = '';
        }
    }

    function switchMobileView(view) {
        state.mobileView = view;
        refs.mobileViews.forEach(section => {
            section.classList.toggle('active', section.dataset.mobileView === view);
        });
        refs.mobileNavButtons.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.mobileNav === view);
        });
    }

    function ensureKrRow() {
        if (!refs.krList) return;
        if (!refs.krList.querySelector('[data-kr-row]')) {
            addKrRow();
        }
    }

    function addKrRow() {
        if (!refs.krTemplate || !refs.krList) return;
        const clone = refs.krTemplate.content.cloneNode(true);
        refs.krList.appendChild(clone);
        updateKrBadges();
    }

    function removeKrRow(row) {
        if (!row || !refs.krList) return;
        if (refs.krList.querySelectorAll('[data-kr-row]').length <= 1) {
            showToast('至少需要一个 KR');
            return;
        }
        row.remove();
        updateKrBadges();
    }

    function updateKrBadges() {
        if (!refs.krList) return;
        refs.krList.querySelectorAll('[data-kr-row]').forEach((row, idx) => {
            const badge = row.querySelector('.kr-badge');
            if (badge) badge.textContent = `KR${idx + 1}`;
        });
    }

    async function loadCycles() {
        const response = await apiFetch('okr_cycle.php', { params: { action: 'list' } });
        state.cycles = response.data || [];
        const cached = localStorage.getItem('okr_current_cycle');
        if (cached && state.cycles.some(c => String(c.id) === cached)) {
            state.currentCycleId = parseInt(cached, 10);
        } else if (!state.currentCycleId && state.cycles.length > 0) {
            state.currentCycleId = state.cycles[0].id;
        }
        updateCycleDisplay();
    }

    function shiftCycle(step) {
        if (!state.cycles.length || !state.currentCycleId) return;
        const index = state.cycles.findIndex(c => c.id === state.currentCycleId);
        const nextIndex = Math.min(Math.max(index + step, 0), state.cycles.length - 1);
        state.currentCycleId = state.cycles[nextIndex].id;
        localStorage.setItem('okr_current_cycle', String(state.currentCycleId));
        hydrate();
    }

    function updateCycleDisplay() {
        const cycle = state.cycles.find(c => c.id === state.currentCycleId);
        if (cycle) {
            const range = `${cycle.start_date} ~ ${cycle.end_date}`;
            if (refs.cycleName) refs.cycleName.textContent = cycle.name;
            if (refs.cycleRange) refs.cycleRange.textContent = range;
            if (refs.cycleRemain) refs.cycleRemain.textContent = `剩余 ${cycle.days_left ?? '-'} 天`;
            if (refs.mobileCycleName) refs.mobileCycleName.textContent = cycle.name;
            if (refs.mobileCycleRemain) refs.mobileCycleRemain.textContent = `${cycle.days_left ?? '-'} 天`;
            localStorage.setItem('okr_current_cycle', String(cycle.id));
        }
    }

    async function loadContainers() {
        if (!state.currentCycleId) return;
        const response = await apiFetch('okr_container.php', {
            params: { action: 'list', cycle_id: state.currentCycleId }
        });
        state.containers = response.data || [];
        const detailPromises = state.containers.map(container =>
            apiFetch('okr_container.php', { params: { action: 'detail', id: container.id } })
                .then(res => res.data)
                .catch(() => null)
        );
        const details = await Promise.all(detailPromises);
        state.containerDetails = {};
        details.filter(Boolean).forEach(detail => {
            state.containerDetails[detail.id] = detail;
        });
        buildKrOptions();
        renderOkrs();
        renderRelations();
    }

    function buildKrOptions() {
        const options = [];
        Object.values(state.containerDetails).forEach(detail => {
            (detail.objectives || []).forEach(obj => {
                (obj.key_results || []).forEach(kr => {
                    options.push({
                        id: kr.id,
                        label: `[${detail.user_name || ''}] ${obj.title} / ${kr.title}`
                    });
                });
            });
        });
        state.krOptions = options;
        if (refs.taskRelationSelect) {
            refs.taskRelationSelect.innerHTML = '<option value="">-- 选择 KR --</option>' +
                options.map(opt => `<option value="${opt.id}">${escapeHtml(opt.label)}</option>`).join('');
        }
    }

    async function loadTasks() {
        const params = {
            action: 'list',
            filter: state.taskFilters.filter
        };
        if (state.taskFilters.status) {
            params.status = state.taskFilters.status;
        }
        const response = await apiFetch('okr_task.php', { params });
        state.tasks = response.data || [];
        renderTasks();
        // 如果当前是日历视图，也更新日历
        if (state.taskViewMode === 'calendar') {
            renderCalendar();
        } else if (state.taskViewMode === 'recent') {
            renderRecentTasks();
        }
    }

    async function loadAllTasks() {
        const params = {
            action: 'list',
            filter: 'all'
        };
        if (state.allTaskFilters.status) {
            params.status = state.allTaskFilters.status;
        }
        const response = await apiFetch('okr_task.php', { params });
        state.allTasks = response.data || [];
        renderAllTasks();
    }

    async function loadAllOkrs() {
        if (!state.currentCycleId) return;
        try {
            // 使用list操作，不传user_id参数即可获取所有有权限的OKR
            const response = await apiFetch('okr_container.php', {
                params: { action: 'list', cycle_id: state.currentCycleId }
            });
            state.allOkrs = response.data || [];
            // 加载详情
            const detailPromises = state.allOkrs.map(container =>
                apiFetch('okr_container.php', { params: { action: 'detail', id: container.id } })
                    .then(res => res.data)
                    .catch(() => null)
            );
            const details = await Promise.all(detailPromises);
            state.allOkrDetails = {};
            details.filter(Boolean).forEach(detail => {
                state.allOkrDetails[detail.id] = detail;
            });
            renderAllOkrs();
        } catch (error) {
            console.error('加载全部OKR失败:', error);
            state.allOkrs = [];
            state.allOkrDetails = {};
            renderAllOkrs();
        }
    }

    function updateAllOkrFilter(filterType, value) {
        state.allOkrFilters[filterType] = value;
        // 更新按钮状态
        document.querySelectorAll(`[data-all-okr-filter="${filterType}"]`).forEach(btn => {
            btn.classList.toggle('active', btn.dataset.value === value);
        });
        renderAllOkrs();
    }

    function renderAllOkrs() {
        if (!refs.allOkrList) return;
        
        // 应用筛选
        let filtered = state.allOkrs.filter(container => {
            const detail = state.allOkrDetails[container.id] || {};
            
            // 类型筛选
            if (state.allOkrFilters.type && container.level !== state.allOkrFilters.type) {
                return false;
            }
            
            // 状态筛选（基于进度）
            if (state.allOkrFilters.status) {
                const progress = container.progress || 0;
                if (state.allOkrFilters.status === 'pending' && progress > 0) return false;
                if (state.allOkrFilters.status === 'in_progress' && (progress === 0 || progress === 100)) return false;
                if (state.allOkrFilters.status === 'completed' && progress < 100) return false;
                if (state.allOkrFilters.status === 'failed' && progress >= 100) return false;
            }
            
            // 负责人筛选
            if (state.allOkrFilters.owner) {
                const ownerName = (detail.user_name || '').toLowerCase();
                if (!ownerName.includes(state.allOkrFilters.owner.toLowerCase())) {
                    return false;
                }
            }
            
            return true;
        });
        
        if (filtered.length === 0) {
            refs.allOkrList.innerHTML = '';
            if (refs.allOkrEmptyState) {
                refs.allOkrEmptyState.hidden = false;
            }
            return;
        }
        
        if (refs.allOkrEmptyState) {
            refs.allOkrEmptyState.hidden = true;
        }
        
        // 按类型分组
        const companyOkrs = filtered.filter(c => c.level === 'company');
        const deptOkrs = filtered.filter(c => c.level === 'department');
        const personalOkrs = filtered.filter(c => c.level === 'personal');
        
        let html = '';
        
        if (companyOkrs.length > 0) {
            html += `
                <div style="margin-bottom: 24px;">
                    <h3 style="font-size: 14px; color: var(--text-secondary); margin-bottom: 12px; font-weight: 600;">
                        🏢 公司级 (${companyOkrs.length})
                    </h3>
                    ${companyOkrs.map(container => renderAllOkrCard(container)).join('')}
                </div>
            `;
        }
        
        if (deptOkrs.length > 0) {
            html += `
                <div style="margin-bottom: 24px;">
                    <h3 style="font-size: 14px; color: var(--text-secondary); margin-bottom: 12px; font-weight: 600;">
                        🏬 部门级 (${deptOkrs.length})
                    </h3>
                    ${deptOkrs.map(container => renderAllOkrCard(container)).join('')}
                </div>
            `;
        }
        
        if (personalOkrs.length > 0) {
            html += `
                <div style="margin-bottom: 24px;">
                    <h3 style="font-size: 14px; color: var(--text-secondary); margin-bottom: 12px; font-weight: 600;">
                        👤 个人级 (${personalOkrs.length})
                    </h3>
                    ${personalOkrs.map(container => renderAllOkrCard(container)).join('')}
                </div>
            `;
        }
        
        refs.allOkrList.innerHTML = html;
    }

    function renderAllOkrCard(container) {
        const detail = state.allOkrDetails[container.id] || {};
        const objectives = detail.objectives || [];
        const currentUserId = window.OKR_BOOTSTRAP?.user?.id;
        const isMyOkr = container.user_id == currentUserId;
        
        return `
            <div class="okr-container" data-container-id="${container.id}">
                <div class="okr-header">
                    <div>
                        <div style="font-weight: 600; font-size: 16px; margin-bottom: 4px;">
                            ${escapeHtml(detail.user_name || '未命名')}
                        </div>
                        <div style="font-size: 13px; color: var(--text-secondary);">
                            ${escapeHtml(detail.cycle_name || '')} · ${escapeHtml(container.level || '')}
                        </div>
                    </div>
                    <div class="okr-card-actions">
                        ${isMyOkr ? `
                            <button class="okr-btn secondary small" data-action="edit-okr" data-container-id="${container.id}" title="编辑">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                </svg>
                            </button>
                            <button class="okr-btn secondary small" data-action="delete-okr" data-container-id="${container.id}" title="删除">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <polyline points="3 6 5 6 21 6"></polyline>
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                </svg>
                            </button>
                        ` : ''}
                        <button class="okr-btn secondary small" data-action="open-comment" data-target-type="okr" data-target-id="${container.id}" title="评论">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="okr-body">
                    ${objectives.map((obj, index) => `
                        <div class="objective" style="margin-bottom: 16px;">
                            <div class="objective-title" style="font-size: 15px; font-weight: 600; margin-bottom: 8px;">
                                O${index + 1} · ${escapeHtml(obj.title)}
                            </div>
                            <div class="okr-progress" style="margin-bottom: 12px;">
                                <div class="okr-progress-bar">
                                    <div class="okr-progress-fill" style="width:${obj.progress || 0}%"></div>
                                </div>
                                <span style="font-size: 12px; color: var(--text-secondary);">${obj.progress || 0}%</span>
                            </div>
                            <div class="kr-list">
                                ${(obj.key_results || []).map((kr, krIndex) => `
                                    <div class="kr-item" style="padding: 8px; background: var(--card-bg); border-radius: 6px; margin-bottom: 8px;">
                                        <div style="flex: 1;">
                                            <div style="font-weight: 500; margin-bottom: 4px;">
                                                <strong>KR${krIndex + 1}</strong> · ${escapeHtml(kr.title)}
                                            </div>
                                            <div class="kr-meta" style="font-size: 12px; color: var(--text-secondary); display: flex; gap: 12px;">
                                                <span>进度 ${kr.progress || 0}%</span>
                                                <span>权重 ${kr.weight || 0}%</span>
                                                <span>信心 ${kr.confidence || 0}</span>
                                            </div>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }

    function renderAll() {
        renderOkrs();
        renderAllOkrs();
        renderTasks();
        renderAllTasks();
        renderRelations();
    }

    function renderOkrs() {
        if (refs.containerList) {
            const html = state.containers.map(container => renderContainerCard(container)).join('');
            refs.containerList.innerHTML = html;
            if (refs.okrEmptyState) {
                refs.okrEmptyState.hidden = state.containers.length > 0;
            }
        }

        if (refs.mobileOkrList) {
            refs.mobileOkrList.innerHTML = state.containers.map(container => renderMobileCard(container)).join('');
        }
    }

    function renderContainerCard(container) {
        const detail = state.containerDetails[container.id] || {};
        const objectives = detail.objectives || [];
        const currentUserId = window.OKR_BOOTSTRAP?.user?.id;
        const isMyOkr = container.user_id == currentUserId;
        return `
            <div class="okr-card" data-container-id="${container.id}">
                <div class="okr-card-header">
                    <div>
                        <div class="okr-card-title">${escapeHtml(detail.user_name || '未命名')} · ${escapeHtml(container.level || '')}</div>
                        <div class="okr-badges">${escapeHtml(detail.cycle_name || '')}</div>
                    </div>
                    <div class="okr-card-actions">
                        ${isMyOkr ? `
                            <button class="okr-btn secondary small" data-action="edit-okr" data-container-id="${container.id}" title="编辑">✏️</button>
                            <button class="okr-btn secondary small" data-action="delete-okr" data-container-id="${container.id}" title="删除">🗑️</button>
                        ` : ''}
                        <button class="okr-btn secondary" data-action="open-comment" data-target-type="okr" data-target-id="${container.id}">💬</button>
                    </div>
                </div>
                ${objectives.map((obj, index) => `
                    <div class="objective">
                        <div class="objective-title">O${index + 1} · ${escapeHtml(obj.title)}</div>
                        <div class="okr-progress" style="margin-top:8px;">
                            <div class="okr-progress-bar">
                                <div class="okr-progress-fill" style="width:${obj.progress || 0}%"></div>
                            </div>
                            <span>${obj.progress || 0}%</span>
                        </div>
                        <div class="kr-list">
                            ${(obj.key_results || []).map((kr, krIndex) => `
                                <div class="kr-item">
                                    <div>
                                        <div><strong>KR${krIndex + 1}</strong> · ${escapeHtml(kr.title)}</div>
                                        <div class="kr-meta">
                                            <span>进度 ${kr.progress}%</span>
                                            <span>权重 ${kr.weight}%</span>
                                            <span>信心 ${kr.confidence}</span>
                                        </div>
                                    </div>
                                    <div class="kr-actions">
                                        <button class="icon-btn" title="评论" data-action="open-comment" data-target-type="kr" data-target-id="${kr.id}">💬</button>
                                        <button class="icon-btn" title="添加任务" data-action="open-create-task" data-kr-id="${kr.id}">＋</button>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    function renderMobileCard(container) {
        const detail = state.containerDetails[container.id] || {};
        const objectives = detail.objectives || [];
        return `
            <div class="okr-card">
                <div class="okr-card-header">
                    <div>
                        <div class="okr-card-title">${escapeHtml(detail.user_name || '未命名')}</div>
                        <div style="font-size:12px;color:var(--text-secondary);">${escapeHtml(detail.cycle_name || '')}</div>
                    </div>
                    <div class="okr-progress" style="width:120px;">
                        <div class="okr-progress-bar">
                            <div class="okr-progress-fill" style="width:${container.progress || 0}%"></div>
                        </div>
                        <span>${container.progress || 0}%</span>
                    </div>
                </div>
                ${(objectives || []).map(obj => `
                    <div class="kr-pill">
                        <div class="kr-pill-title">${escapeHtml(obj.title)}</div>
                        ${(obj.key_results || []).slice(0, 2).map(kr => `
                            <div class="kr-pill-meta">
                                <span>${escapeHtml(kr.title)}</span>
                                <span>${kr.progress}%</span>
                            </div>
                        `).join('')}
                    </div>
                `).join('')}
            </div>
        `;
    }

    function renderTasks() {
        const currentUserId = window.OKR_BOOTSTRAP?.user?.id;
        
        if (refs.taskTable) {
            const tbody = refs.taskTable.querySelector('tbody');
            if (!tbody) return;
            const rows = state.tasks.map(task => {
                const isMyTask = task.executor_id == currentUserId;
                return `
                <tr>
                    <td><span class="task-priority-dot ${task.priority || 'medium'}"></span>${escapeHtml(task.title)}</td>
                    <td><span class="task-status ${task.status}">${statusLabel(task.status)}</span></td>
                    <td>${escapeHtml(task.executor_name || '')}</td>
                    <td>${task.due_date || '-'}</td>
                    <td>${task.relations?.map(rel => rel.relation_type + '#' + rel.relation_id).join(', ') || '-'}</td>
                    <td>
                        ${isMyTask ? `
                            <button class="icon-btn" data-action="edit-task" data-task-id="${task.id}" title="编辑">✏️</button>
                            <button class="icon-btn" data-action="delete-task" data-task-id="${task.id}" title="删除">🗑️</button>
                        ` : ''}
                        <button class="icon-btn" data-action="open-comment" data-target-type="task" data-target-id="${task.id}">💬</button>
                    </td>
                </tr>
            `;
            }).join('');
            tbody.innerHTML = rows || '';
            if (refs.taskEmpty) {
                refs.taskEmpty.hidden = state.tasks.length > 0;
            }
        }

        const mobileList = refs.mobileTaskList || refs.mobileTaskListStandalone;
        if (mobileList) {
            const cards = state.tasks.map(task => {
                const isMyTask = task.executor_id == currentUserId;
                return `
                <div class="task-card">
                    <div class="task-title">${escapeHtml(task.title)}</div>
                    <div class="task-meta">
                        <span>${statusLabel(task.status)}</span>
                        <span>负责人 ${escapeHtml(task.executor_name || '')}</span>
                        <span>截止 ${task.due_date || '-'}</span>
                    </div>
                    ${isMyTask ? `
                        <div class="task-actions">
                            <button class="icon-btn" data-action="edit-task" data-task-id="${task.id}">✏️</button>
                            <button class="icon-btn" data-action="delete-task" data-task-id="${task.id}">🗑️</button>
                        </div>
                    ` : ''}
                </div>
            `;
            }).join('');
            if (refs.mobileTaskList) refs.mobileTaskList.innerHTML = cards;
            if (refs.mobileTaskListStandalone) refs.mobileTaskListStandalone.innerHTML = cards;
        }

        if (refs.mobileSummaryTasks) {
            const completed = state.tasks.filter(task => task.status === 'completed').length;
            refs.mobileSummaryTasks.textContent = `${completed}/${state.tasks.length}`;
        }
    }

    function renderAllTasks() {
        if (refs.allTaskTable) {
            const tbody = refs.allTaskTable.querySelector('tbody');
            if (!tbody) return;
            const rows = state.allTasks.map(task => `
                <tr>
                    <td><span class="task-priority-dot ${task.priority || 'medium'}"></span>${escapeHtml(task.title)}</td>
                    <td><span class="task-status ${task.status}">${statusLabel(task.status)}</span></td>
                    <td>${escapeHtml(task.executor_name || '')}</td>
                    <td>${task.due_date || '-'}</td>
                    <td>${task.relations?.map(rel => rel.relation_type + '#' + rel.relation_id).join(', ') || '-'}</td>
                    <td>
                        <button class="icon-btn" data-action="open-comment" data-target-type="task" data-target-id="${task.id}">💬</button>
                    </td>
                </tr>
            `).join('');
            tbody.innerHTML = rows || '';
            if (refs.allTaskEmpty) {
                refs.allTaskEmpty.hidden = state.allTasks.length > 0;
            }
        }
    }

    function renderRelations() {
        // 收集所有目标
        const allObjectives = [];
        state.containers.forEach(container => {
            const detail = state.containerDetails[container.id] || {};
            (detail.objectives || []).forEach(obj => {
                allObjectives.push({
                    ...obj,
                    containerLevel: container.level,
                    containerUserName: detail.user_name || '',
                    departmentName: detail.department_name || ''
                });
            });
        });

        // 按级别分组
        const grouped = { company: [], department: [], personal: [] };
        allObjectives.forEach(obj => {
            if (grouped[obj.containerLevel]) {
                grouped[obj.containerLevel].push(obj);
            }
        });

        // 应用筛选
        const filteredGrouped = { company: [], department: [], personal: [] };
        Object.keys(grouped).forEach(level => {
            filteredGrouped[level] = grouped[level].filter(obj => {
                if (state.relationFilter === 'all') return true;
                return obj.containerLevel === state.relationFilter;
            });
        });

        if (refs.relationCanvas) {
            const levels = ['company', 'department', 'personal'];
            const hasData = levels.some(level => filteredGrouped[level].length > 0);
            
            let html = '';
            levels.forEach((level, levelIndex) => {
                const items = filteredGrouped[level];
                if (items.length === 0) return;
                
                const showConnector = levelIndex < levels.length - 1 && 
                    levels.slice(levelIndex + 1).some(l => filteredGrouped[l].length > 0);
                
                html += `<div class="relation-column">`;
                items.forEach(obj => {
                    html += renderRelationCard(obj, level, showConnector);
                });
                html += `</div>`;
            });

            if (!hasData) {
                html = '';
            }

            refs.relationCanvas.innerHTML = html;
            
            if (refs.relationEmpty) {
                refs.relationEmpty.hidden = hasData;
            }
            
            updateRelationTransform();
        }

        if (refs.mobileRelation) {
            refs.mobileRelation.innerHTML = ['company', 'department', 'personal'].map(level => `
                <div class="relation-column">
                    <h4>${level === 'company' ? '公司级' : level === 'department' ? '部门级' : '个人级'}</h4>
                    ${filteredGrouped[level].map(obj => `<div class="relation-chip">${escapeHtml(obj.title || '')}</div>`).join('') || '<div class="relation-chip">暂无</div>'}
                </div>
            `).join('');
        }

        if (refs.mobileSummaryProgress) {
            const avg = state.containers.length
                ? (state.containers.reduce((sum, item) => sum + (item.progress || 0), 0) / state.containers.length).toFixed(0)
                : '0';
            refs.mobileSummaryProgress.textContent = `${avg}%`;
        }
    }

    function renderRelationCard(obj, levelClass, showConnector, relationCount = 0) {
        const levelText = obj.containerLevel === 'company' ? '公司级' : 
                         (obj.departmentName || (obj.containerLevel === 'department' ? '部门级' : '个人'));
        
        const progress = obj.progress || 0;
        const ownerName = obj.containerUserName || '未知';
        const ownerInitial = ownerName.charAt(0);
        
        let krsHtml = '';
        if (state.relationShowKr && obj.key_results && obj.key_results.length > 0) {
            const krsToShow = obj.key_results.slice(0, 4);
            krsHtml = `<div class="relation-card-krs">` + krsToShow.map((kr, i) => {
                const krTitle = kr.title || '';
                const displayTitle = krTitle.length > 30 ? krTitle.substring(0, 30) + '...' : krTitle;
                // 假设每个KR可能有关联数量
                const krRelationCount = kr.relation_count || 0;
                return `<div class="relation-card-kr" data-kr-id="${kr.id}">
                    <span class="relation-card-kr-label">KR${i + 1}</span>
                    <span class="relation-card-kr-title">${escapeHtml(displayTitle)}</span>
                    <span class="relation-card-kr-progress">${kr.progress || 0}%</span>
                    ${krRelationCount > 0 ? `<span class="kr-relation-count">${krRelationCount}</span>` : ''}
                </div>`;
            }).join('') + `</div>`;
            
            if (obj.key_results.length > 4) {
                krsHtml = krsHtml.replace('</div><!-- end krs -->', '') + 
                    `<div class="relation-card-kr" style="color: var(--primary-color); justify-content: center;">还有 ${obj.key_results.length - 4} 个KR...</div></div>`;
            }
        }

        // 关联数量标识
        const relationCountHtml = relationCount > 0 ? `<span class="relation-count">${relationCount}</span>` : '';

        return `
            <div class="relation-card ${levelClass}" data-objective-id="${obj.id}">
                <span class="relation-card-toggle">∧</span>
                <div class="relation-card-title">${escapeHtml(obj.title || '')}</div>
                <div class="relation-card-progress-wrap">
                    <div class="relation-card-progress-bar">
                        <div class="relation-card-progress-fill" style="width: ${progress}%"></div>
                    </div>
                    <span class="relation-card-progress-text">${progress.toFixed(0)}%</span>
                </div>
                <div class="relation-card-meta">
                    <span class="relation-card-level ${levelClass}">${escapeHtml(levelText)}</span>
                    <span class="relation-card-owner">
                        <span class="relation-card-avatar">${escapeHtml(ownerInitial)}</span>
                        ${escapeHtml(ownerName)}
                    </span>
                </div>
                ${krsHtml}
                ${relationCountHtml}
                ${showConnector ? '<div class="connector"></div>' : ''}
            </div>
        `;
    }

    function updateRelationTransform() {
        if (refs.relationCanvasInner) {
            refs.relationCanvasInner.style.transform = `translate(${state.relationPanX}px, ${state.relationPanY}px) scale(${state.relationZoom})`;
        }
        if (refs.zoomLevel) {
            refs.zoomLevel.textContent = Math.round(state.relationZoom * 100) + '%';
        }
    }

    function filterRelation(container) {
        if (state.relationFilter === 'all') return true;
        return container.level === state.relationFilter;
    }

    function openCycleModal() {
        resetCycleForm();
        renderCycleList();
        openModal('cycle');
    }

    function resetCycleForm() {
        if (!refs.forms.cycle) return;
        refs.forms.cycle.reset();
        const idInput = document.getElementById('cycleFormId');
        if (idInput) idInput.value = '';
        const titleEl = document.getElementById('cycleFormTitle');
        if (titleEl) titleEl.textContent = '新建周期';
    }

    function editCycle(cycleId) {
        const cycle = state.cycles.find(c => c.id === parseInt(cycleId, 10));
        if (!cycle) {
            showToast('周期不存在', 'error');
            return;
        }

        if (!refs.forms.cycle) return;

        // 填充表单
        const idInput = document.getElementById('cycleFormId');
        const nameInput = document.getElementById('cycleFormName');
        const typeInput = document.getElementById('cycleFormType');
        const startDateInput = document.getElementById('cycleFormStartDate');
        const endDateInput = document.getElementById('cycleFormEndDate');
        const titleEl = document.getElementById('cycleFormTitle');

        if (idInput) idInput.value = cycle.id;
        if (nameInput) nameInput.value = cycle.name || '';
        if (typeInput) typeInput.value = cycle.type || 'quarter';
        if (startDateInput) startDateInput.value = cycle.start_date || '';
        if (endDateInput) endDateInput.value = cycle.end_date || '';
        if (titleEl) titleEl.textContent = '编辑周期';

        // 滚动到表单区域
        const formSection = document.querySelector('.cycle-form-section');
        if (formSection) {
            formSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function renderCycleList() {
        if (!refs.cycleList) return;
        
        if (!state.cycles || state.cycles.length === 0) {
            refs.cycleList.innerHTML = '<div class="empty-state">暂无周期，请创建新周期</div>';
            return;
        }

        refs.cycleList.innerHTML = state.cycles.map(cycle => {
            const isActive = cycle.id === state.currentCycleId;
            const startDate = cycle.start_date ? new Date(cycle.start_date).toLocaleDateString('zh-CN') : '';
            const endDate = cycle.end_date ? new Date(cycle.end_date).toLocaleDateString('zh-CN') : '';
            const typeText = {
                'month': '月度',
                'quarter': '季度',
                'half': '半年',
                'year': '年度',
                'custom': '自定义'
            }[cycle.type] || cycle.type;
            
            return `
                <div class="cycle-list-item ${isActive ? 'active' : ''}" data-cycle-id="${cycle.id}">
                    <div class="cycle-list-item-info">
                        <span class="cycle-list-item-name">${escapeHtml(cycle.name)}</span>
                        <span class="cycle-list-item-type">${typeText}</span>
                    </div>
                    <div class="cycle-list-item-date">${startDate} ~ ${endDate}</div>
                    <div class="cycle-list-item-actions">
                        <button class="okr-btn small secondary" data-action="edit-cycle" data-cycle-id="${cycle.id}">编辑</button>
                        <button class="okr-btn small ${isActive ? 'primary' : 'secondary'}" data-action="select-cycle" data-cycle-id="${cycle.id}">
                            ${isActive ? '当前' : '切换'}
                        </button>
                        <button class="okr-btn small danger" data-action="delete-cycle" data-cycle-id="${cycle.id}">删除</button>
                    </div>
                </div>
            `;
        }).join('');
    }

    async function saveCycle(formData) {
        const payload = Object.fromEntries(formData.entries());
        payload.action = 'save';
        
        // 验证表单
        if (!payload.name || !payload.name.trim()) {
            showToast('请输入周期名称', 'error');
            return;
        }
        if (!payload.start_date || !payload.end_date) {
            showToast('请选择开始日期和结束日期', 'error');
            return;
        }
        
        const startDate = new Date(payload.start_date);
        const endDate = new Date(payload.end_date);
        if (startDate > endDate) {
            showToast('开始日期不能晚于结束日期', 'error');
            return;
        }

        try {
            await apiFetch('okr_cycle.php', { method: 'POST', body: payload });
            const isEdit = payload.id && parseInt(payload.id, 10) > 0;
            showToast(isEdit ? '周期已更新' : '周期已创建');
            resetCycleForm();
            await loadCycles();
            renderCycleList();
            await loadContainers();
        } catch (error) {
            showToast(error.message || '保存失败', 'error');
        }
    }

    async function deleteCycle(cycleId) {
        showOkrConfirm('确定要删除此周期吗？关联的 OKR 数据也将被删除。', async function() {
            await apiFetch('okr_cycle.php', { method: 'POST', body: { action: 'delete', id: cycleId } });
            showToast('周期已删除');
            await loadCycles();
            renderCycleList();
            await loadContainers();
        });
    }

    async function selectCycle(cycleId) {
        state.currentCycleId = parseInt(cycleId, 10);
        localStorage.setItem('okr_current_cycle', cycleId);
        updateCycleDisplay();
        renderCycleList();
        await loadContainers();
        // 如果周期选择模态框打开，则关闭它
        if (refs.modals.cycleSelect) {
            closeModal(refs.modals.cycleSelect);
        }
    }

    // ========== 周期选择模态框（左上角周期选择器使用） ==========
    function openCycleSelectModal() {
        if (!refs.modals.cycleSelect) return;
        
        // 初始化周期类型选择器
        initCycleTypeSelector();
        
        // 初始化日期输入
        initCycleDateInputs();
        
        // 渲染快速选择列表
        renderCycleQuickSelectList();
        
        openModal('cycleSelect');
    }

    function initCycleTypeSelector() {
        const selector = refs.modals.cycleSelect?.querySelector('.cycle-type-selector');
        if (!selector) return;
        
        // 移除所有active类
        selector.querySelectorAll('.segment-item').forEach(item => {
            item.classList.remove('active');
        });
        
        // 根据当前周期设置默认选中
        const currentCycle = state.cycles.find(c => c.id === state.currentCycleId);
        if (currentCycle) {
            const typeMap = {
                'week': 7,
                '2week': 14,
                'month': 30,
                'quarter': 90,
                '4month': 120,
                'half_year': 180,
                'year': 365
            };
            const days = typeMap[currentCycle.type] || 'custom';
            const item = selector.querySelector(`[data-days="${days}"]`);
            if (item) item.classList.add('active');
        } else {
            // 默认选中1个月
            const defaultItem = selector.querySelector('[data-days="30"]');
            if (defaultItem) defaultItem.classList.add('active');
        }
        
        // 绑定点击事件
        selector.querySelectorAll('.segment-item').forEach(item => {
            item.addEventListener('click', function() {
                selector.querySelectorAll('.segment-item').forEach(i => i.classList.remove('active'));
                this.classList.add('active');
                updateCycleDatesFromType(this.dataset.days);
            });
        });
    }

    function updateCycleDatesFromType(days) {
        if (days === 'custom') return;
        
        const startInput = document.getElementById('cycleSelectStart');
        const endInput = document.getElementById('cycleSelectEnd');
        if (!startInput || !endInput) return;
        
        const today = new Date();
        const startDate = new Date(today);
        startDate.setDate(1); // 设置为当月第一天
        
        const endDate = new Date(startDate);
        const daysNum = parseInt(days, 10);
        if (daysNum <= 30) {
            // 周或月：直接加天数
            endDate.setDate(startDate.getDate() + daysNum - 1);
        } else {
            // 季度、半年、年：加月份
            endDate.setMonth(startDate.getMonth() + daysNum / 30);
            endDate.setDate(0); // 设置为上个月的最后一天
        }
        
        startInput.value = formatDateForInput(startDate);
        endInput.value = formatDateForInput(endDate);
    }

    function formatDateForInput(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function initCycleDateInputs() {
        const startInput = document.getElementById('cycleSelectStart');
        const endInput = document.getElementById('cycleSelectEnd');
        if (!startInput || !endInput) return;
        
        const currentCycle = state.cycles.find(c => c.id === state.currentCycleId);
        if (currentCycle) {
            startInput.value = currentCycle.start_date || '';
            endInput.value = currentCycle.end_date || '';
        } else {
            // 默认设置为当前月份
            const today = new Date();
            const startDate = new Date(today.getFullYear(), today.getMonth(), 1);
            const endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            startInput.value = formatDateForInput(startDate);
            endInput.value = formatDateForInput(endDate);
        }
    }

    function renderCycleQuickSelectList() {
        const container = document.getElementById('cycleQuickSelectList');
        if (!container) return;
        
        if (!state.cycles || state.cycles.length === 0) {
            container.innerHTML = '<div style="text-align: center; color: var(--text-secondary); padding: 20px;">暂无周期</div>';
            return;
        }
        
        // 按开始日期倒序排列
        const sortedCycles = [...state.cycles].sort((a, b) => {
            return new Date(b.start_date) - new Date(a.start_date);
        });
        
        container.innerHTML = sortedCycles.map(cycle => {
            const isActive = cycle.id === state.currentCycleId;
            return `
                <div class="cycle-quick-item ${isActive ? 'active' : ''}" 
                     data-action="select-cycle-quick" 
                     data-cycle-id="${cycle.id}">
                    <span>${escapeHtml(cycle.name)}</span>
                    <span>${isActive ? '✓ 当前' : '›'}</span>
                </div>
            `;
        }).join('');
    }

    async function selectCycleFromQuick(cycleId) {
        await selectCycle(cycleId);
        closeModal(refs.modals.cycleSelect);
        showToast('周期已切换', 'success');
    }

    async function confirmCycleSelect() {
        const startInput = document.getElementById('cycleSelectStart');
        const endInput = document.getElementById('cycleSelectEnd');
        
        if (!startInput || !endInput || !startInput.value || !endInput.value) {
            showToast('请选择开始日期和结束日期', 'error');
            return;
        }
        
        const startDate = new Date(startInput.value);
        const endDate = new Date(endInput.value);
        
        if (startDate > endDate) {
            showToast('开始日期不能晚于结束日期', 'error');
            return;
        }
        
        // 检查是否已存在相同日期的周期
        const existingCycle = state.cycles.find(c => 
            c.start_date === startInput.value && c.end_date === endInput.value
        );
        
        if (existingCycle) {
            await selectCycleFromQuick(existingCycle.id);
            return;
        }
        
        // 创建新周期
        try {
            const activeType = refs.modals.cycleSelect?.querySelector('.segment-item.active');
            const typeMap = {
                '7': 'week',
                '14': '2week',
                '30': 'month',
                '90': 'quarter',
                '120': '4month',
                '180': 'half_year',
                '365': 'year',
                'custom': 'custom'
            };
            const cycleType = typeMap[activeType?.dataset.days] || 'custom';
            
            // 生成周期名称
            const start = new Date(startInput.value);
            const end = new Date(endInput.value);
            let cycleName = '';
            if (cycleType === 'month') {
                cycleName = `${start.getFullYear()}年${start.getMonth() + 1}月`;
            } else if (cycleType === 'quarter') {
                const quarter = Math.floor(start.getMonth() / 3) + 1;
                cycleName = `${start.getFullYear()}年第${quarter}季度`;
            } else if (cycleType === 'year') {
                cycleName = `${start.getFullYear()}年`;
            } else {
                cycleName = `${formatDateForInput(start)} ~ ${formatDateForInput(end)}`;
            }
            
            const payload = {
                action: 'save',
                name: cycleName,
                type: cycleType,
                start_date: startInput.value,
                end_date: endInput.value
            };
            
            const response = await apiFetch('okr_cycle.php', { method: 'POST', body: payload });
            showToast('周期已创建', 'success');
            
            // 重新加载周期列表并选择新创建的周期
            await loadCycles();
            const newCycle = state.cycles.find(c => 
                c.start_date === startInput.value && c.end_date === endInput.value
            );
            if (newCycle) {
                await selectCycleFromQuick(newCycle.id);
            }
        } catch (error) {
            showToast(error.message || '创建周期失败', 'error');
        }
    }

    // 周期导航函数（前后切换）
    async function prevCycle() {
        if (!state.cycles.length || !state.currentCycleId) return;
        const index = state.cycles.findIndex(c => c.id === state.currentCycleId);
        const prevIndex = index > 0 ? index - 1 : state.cycles.length - 1;
        await selectCycle(state.cycles[prevIndex].id);
    }

    async function nextCycle() {
        if (!state.cycles.length || !state.currentCycleId) return;
        const index = state.cycles.findIndex(c => c.id === state.currentCycleId);
        const nextIndex = index < state.cycles.length - 1 ? index + 1 : 0;
        await selectCycle(state.cycles[nextIndex].id);
    }

    async function createOkr() {
        // 如果是编辑模式，调用保存编辑函数
        if (state.editingContainerId) {
            await saveOkrEdit();
            return;
        }

        try {
            if (!refs.forms.container) return;
            if (!state.currentCycleId) {
                showToast('请选择周期');
                return;
            }
            const formData = new FormData(refs.forms.container);
            const objectiveTitle = (formData.get('objective_title') || '').trim();
            if (!objectiveTitle) {
                showToast('请输入目标');
                return;
            }
            const krRows = Array.from(refs.krList?.querySelectorAll('[data-kr-row]') || []);
            if (!krRows.length) {
                showToast('请至少添加一个 KR');
                return;
            }
            const krPayload = krRows.map((row, index) => {
                const titleEl = row.querySelector('textarea[name="kr_title[]"]');
                if (!titleEl) {
                    throw new Error('无法找到 KR 标题输入框');
                }
                return {
                    title: titleEl.value.trim(),
                    target_value: row.querySelector('input[name="kr_target[]"]')?.value || 100,
                    unit: row.querySelector('input[name="kr_unit[]"]')?.value || '%',
                    weight: row.querySelector('input[name="kr_weight[]"]')?.value || 25,
                    confidence: row.querySelector('input[name="kr_confidence[]"]')?.value || 5,
                    rowElement: row
                };
            }).filter(kr => kr.title);
            if (!krPayload.length) {
                showToast('请完善 KR');
                return;
            }

            const containerPayload = {
                action: 'save',
                cycle_id: state.currentCycleId,
                level: formData.get('level'),
                user_id: formData.get('user_id'),
                department_id: formData.get('department_id') || ''
            };

            const containerRes = await apiFetch('okr_container.php', { method: 'POST', body: containerPayload });
            if (!containerRes.data || !containerRes.data.id) {
                throw new Error('创建容器失败：' + (containerRes.message || '未知错误'));
            }
            const containerId = containerRes.data.id;

            // 获取对齐的parent_id（只取第一个objective类型的对齐）
            let parentId = null;
            if (state.pendingAlignments && state.pendingAlignments.length > 0) {
                const objectiveAlign = state.pendingAlignments.find(a => a.type === 'objective');
                if (objectiveAlign) {
                    parentId = parseInt(objectiveAlign.id, 10);
                }
            }

            const objectiveRes = await apiFetch('okr_objective.php', {
                method: 'POST',
                body: {
                    action: 'save',
                    container_id: containerId,
                    title: objectiveTitle,
                    parent_id: parentId || ''
                }
            });
            if (!objectiveRes.data || !objectiveRes.data.id) {
                throw new Error('创建目标失败：' + (objectiveRes.message || '未知错误'));
            }
            const objectiveId = objectiveRes.data.id;

            for (const [index, kr] of krPayload.entries()) {
                const krRes = await apiFetch('okr_objective.php', {
                    method: 'POST',
                    body: {
                        action: 'kr_save',
                        objective_id: objectiveId,
                        title: kr.title,
                        target_value: kr.target_value,
                        current_value: 0,
                        start_value: 0,
                        unit: kr.unit,
                        weight: kr.weight,
                        confidence: kr.confidence,
                        sort_order: index + 1
                    }
                });
                if (!krRes.success) {
                    throw new Error('创建关键结果失败：' + (krRes.message || '未知错误'));
                }

                // 保存任务关联
                const krData = krPayload[index];
                if (krData && krData.rowElement) {
                    const taskIdsInput = krData.rowElement.querySelector('.kr-task-ids-input');
                    if (taskIdsInput && taskIdsInput.value) {
                        const taskIds = taskIdsInput.value.split(',').filter(id => id.trim());
                        const krId = krRes.data?.id;
                        if (krId && taskIds.length > 0) {
                            // 为每个任务添加关联
                            for (const taskId of taskIds) {
                                try {
                                    await apiFetch('okr_task.php', {
                                        method: 'POST',
                                        body: {
                                            action: 'add_relation',
                                            task_id: taskId.trim(),
                                            relation_type: 'kr',
                                            relation_id: krId
                                        }
                                    });
                                } catch (error) {
                                    console.warn('关联任务失败:', error);
                                }
                            }
                        }
                    }
                }
            }

            showToast('OKR 创建成功');
            closeModal(refs.modals.okr);
            refs.forms.container.reset();
            refs.krList.innerHTML = '';
            ensureKrRow();
            await Promise.all([loadContainers(), loadAllOkrs()]);
        } catch (error) {
            console.error('创建 OKR 失败:', error);
            showToast(error.message || '创建 OKR 失败，请稍后重试');
        }
    }

    async function editOkr(containerId) {
        const detail = state.containerDetails[containerId];
        if (!detail || !detail.objectives || detail.objectives.length === 0) {
            showToast('OKR不存在或已删除', 'error');
            return;
        }

        const obj = detail.objectives[0]; // 目前一个容器只有一个目标
        if (!obj) {
            showToast('目标不存在', 'error');
            return;
        }

        // 填充表单
        if (refs.forms.container) {
            const objectiveInput = refs.forms.container.querySelector('textarea[name="objective_title"]');
            if (objectiveInput) objectiveInput.value = obj.title;
        }

        // 填充KR列表
        if (refs.krList) {
            refs.krList.innerHTML = '';
            (obj.key_results || []).forEach((kr, index) => {
                addKrRow({
                    title: kr.title,
                    target: kr.target_value,
                    unit: kr.unit,
                    weight: kr.weight,
                    confidence: kr.confidence
                });
            });
        }

        // 保存编辑状态
        state.editingContainerId = containerId;
        state.editingObjectiveId = obj.id;

        // 打开编辑模态框
        openModal('okr');
        
        // 更新标题
        const modalTitle = refs.modals.okr?.querySelector('.okr-modal__header h3');
        if (modalTitle) modalTitle.textContent = '编辑 OKR';
    }

    async function saveOkrEdit() {
        if (!state.editingContainerId || !state.editingObjectiveId) {
            showToast('编辑状态异常', 'error');
            return;
        }

        if (!refs.forms.container) return;
        const formData = new FormData(refs.forms.container);
        const objectiveTitle = (formData.get('objective_title') || '').trim();
        if (!objectiveTitle) {
            showToast('请输入目标');
            return;
        }

        const krRows = Array.from(refs.krList?.querySelectorAll('[data-kr-row]') || []);
        const krPayload = krRows.map(row => ({
            title: row.querySelector('textarea[name="kr_title[]"]').value.trim(),
            target_value: row.querySelector('input[name="kr_target[]"]').value || 100,
            unit: row.querySelector('input[name="kr_unit[]"]').value || '%',
            weight: row.querySelector('input[name="kr_weight[]"]').value || 25,
            confidence: row.querySelector('input[name="kr_confidence[]"]').value || 5
        })).filter(kr => kr.title);

        if (!krPayload.length) {
            showToast('请至少添加一个 KR');
            return;
        }

        try {
            // 更新目标
            await apiFetch('okr_objective.php', {
                method: 'POST',
                body: {
                    action: 'save',
                    id: state.editingObjectiveId,
                    container_id: state.editingContainerId,
                    title: objectiveTitle
                }
            });

            // 获取当前KR列表
            const detail = state.containerDetails[state.editingContainerId];
            const currentKrs = detail?.objectives?.[0]?.key_results || [];
            const currentKrIds = currentKrs.map(kr => kr.id);

            // 更新或创建KR
            for (const [index, kr] of krPayload.entries()) {
                const existingKr = currentKrs[index];
                if (existingKr) {
                    // 更新现有KR
                    await apiFetch('okr_objective.php', {
                        method: 'POST',
                        body: {
                            action: 'kr_save',
                            id: existingKr.id,
                            objective_id: state.editingObjectiveId,
                            title: kr.title,
                            target_value: kr.target_value,
                            unit: kr.unit,
                            weight: kr.weight,
                            confidence: kr.confidence,
                            sort_order: index + 1
                        }
                    });
                } else {
                    // 创建新KR
                    await apiFetch('okr_objective.php', {
                        method: 'POST',
                        body: {
                            action: 'kr_save',
                            objective_id: state.editingObjectiveId,
                            title: kr.title,
                            target_value: kr.target_value,
                            unit: kr.unit,
                            weight: kr.weight,
                            confidence: kr.confidence,
                            sort_order: index + 1
                        }
                    });
                }
            }

            // 删除多余的KR
            if (currentKrIds.length > krPayload.length) {
                for (let i = krPayload.length; i < currentKrIds.length; i++) {
                    await apiFetch('okr_objective.php', {
                        method: 'POST',
                        body: {
                            action: 'kr_delete',
                            id: currentKrIds[i]
                        }
                    });
                }
            }

            showToast('OKR 更新成功');
            closeModal(refs.modals.okr);
            refs.forms.container.reset();
            refs.krList.innerHTML = '';
            ensureKrRow();
            state.editingContainerId = null;
            state.editingObjectiveId = null;
            await Promise.all([loadContainers(), loadAllOkrs()]);
        } catch (error) {
            showToast(error.message || '更新失败', 'error');
        }
    }

    async function deleteOkr(containerId) {
        showOkrConfirm('确定要删除此 OKR 吗？所有关联的目标、关键结果和任务都将被删除。', async function() {
            try {
                await apiFetch('okr_container.php', {
                    method: 'POST',
                    body: {
                        action: 'delete',
                        id: containerId
                    }
                });
                showToast('OKR 已删除');
                await Promise.all([loadContainers(), loadAllOkrs()]);
            } catch (error) {
                showToast(error.message || '删除失败', 'error');
            }
        });
    }

    async function createTask() {
        // 如果是编辑模式，调用保存编辑函数
        if (state.editingTaskId) {
            await saveTaskEdit();
            return;
        }

        if (!refs.forms.task) return;
        const formData = new FormData(refs.forms.task);
        const title = (formData.get('title') || '').trim();
        if (!title) {
            showToast('请输入任务标题');
            return;
        }
        const relations = [];
        if (formData.get('relation_kr')) {
            relations.push({ type: 'kr', id: parseInt(formData.get('relation_kr'), 10) });
        }
        const payload = {
            action: 'save',
            title,
            description: formData.get('description') || '',
            priority: formData.get('priority') || 'medium',
            status: formData.get('status') || 'pending',
            start_date: formData.get('start_date') || '',
            due_date: formData.get('due_date') || '',
            executor_id: formData.get('executor_id'),
            assigner_id: BOOT.user.id,
            assistant_ids: '[]',
            relations: JSON.stringify(relations)
        };
        const taskRes = await apiFetch('okr_task.php', { method: 'POST', body: payload });
        showToast('任务创建成功');
        closeModal(refs.modals.task);
        refs.forms.task.reset();
        
        // 如果是从KR创建的任务，自动关联到KR
        if (state.pendingKrTask && taskRes.data && taskRes.data.id) {
            const taskIdsInput = state.pendingKrTask.querySelector('.kr-task-ids-input');
            if (taskIdsInput) {
                const currentIds = taskIdsInput.value ? taskIdsInput.value.split(',') : [];
                if (!currentIds.includes(String(taskRes.data.id))) {
                    currentIds.push(String(taskRes.data.id));
                    taskIdsInput.value = currentIds.join(',');
                    
                    // 更新显示
                    const tasksCountEl = state.pendingKrTask.querySelector('.kr-tasks-count');
                    if (tasksCountEl) {
                        const count = currentIds.length;
                        tasksCountEl.textContent = `${count}个任务`;
                        tasksCountEl.classList.add('has-tasks');
                        tasksCountEl.setAttribute('data-count', count);
                    }
                }
            }
            state.pendingKrTask = null;
        }
        
        await loadTasks();
    }

    async function editTask(taskId) {
        const task = state.tasks.find(t => t.id == taskId) || state.allTasks.find(t => t.id == taskId);
        if (!task) {
            showToast('任务不存在', 'error');
            return;
        }

        if (!refs.forms.task) return;

        // 填充表单
        const titleInput = refs.forms.task.querySelector('input[name="title"]');
        const descriptionInput = refs.forms.task.querySelector('textarea[name="description"]');
        const prioritySelect = refs.forms.task.querySelector('select[name="priority"]');
        const statusSelect = refs.forms.task.querySelector('select[name="status"]');
        const startDateInput = refs.forms.task.querySelector('input[name="start_date"]');
        const dueDateInput = refs.forms.task.querySelector('input[name="due_date"]');
        const executorSelect = refs.forms.task.querySelector('select[name="executor_id"]');
        const relationSelect = refs.forms.task.querySelector('select[name="relation_kr"]');

        if (titleInput) titleInput.value = task.title || '';
        if (descriptionInput) descriptionInput.value = task.description || '';
        if (prioritySelect) prioritySelect.value = task.priority || 'medium';
        if (statusSelect) statusSelect.value = task.status || 'pending';
        if (startDateInput) startDateInput.value = task.start_date || '';
        if (dueDateInput) dueDateInput.value = task.due_date || '';
        if (executorSelect) executorSelect.value = task.executor_id || '';
        
        // 设置关联的KR
        if (relationSelect && task.relations && task.relations.length > 0) {
            const krRelation = task.relations.find(r => r.relation_type === 'kr');
            if (krRelation) {
                relationSelect.value = krRelation.relation_id;
            }
        }

        // 保存编辑状态
        state.editingTaskId = taskId;

        // 打开编辑模态框
        openModal('task');
        
        // 更新标题
        const modalTitle = refs.modals.task?.querySelector('.okr-modal__header h3');
        if (modalTitle) modalTitle.textContent = '编辑任务';
    }

    async function saveTaskEdit() {
        if (!state.editingTaskId) {
            showToast('编辑状态异常', 'error');
            return;
        }

        if (!refs.forms.task) return;
        const formData = new FormData(refs.forms.task);
        const title = (formData.get('title') || '').trim();
        if (!title) {
            showToast('请输入任务标题');
            return;
        }

        const relations = [];
        if (formData.get('relation_kr')) {
            relations.push({ type: 'kr', id: parseInt(formData.get('relation_kr'), 10) });
        }

        const payload = {
            action: 'save',
            id: state.editingTaskId,
            title,
            description: formData.get('description') || '',
            priority: formData.get('priority') || 'medium',
            status: formData.get('status') || 'pending',
            start_date: formData.get('start_date') || '',
            due_date: formData.get('due_date') || '',
            executor_id: formData.get('executor_id'),
            relations: JSON.stringify(relations)
        };

        try {
            await apiFetch('okr_task.php', { method: 'POST', body: payload });
            showToast('任务更新成功');
            closeModal(refs.modals.task);
            refs.forms.task.reset();
            state.editingTaskId = null;
            await loadTasks();
        } catch (error) {
            showToast(error.message || '更新失败', 'error');
        }
    }

    async function deleteTask(taskId) {
        showOkrConfirm('确定要删除此任务吗？', async function() {
            try {
                await apiFetch('okr_task.php', {
                    method: 'POST',
                    body: {
                        action: 'delete',
                        id: taskId
                    }
                });
                showToast('任务已删除');
                await loadTasks();
            } catch (error) {
                showToast(error.message || '删除失败', 'error');
            }
        });
    }

    function updateTaskFilter(type, value) {
        if (state.taskFilters[type] === value) return;
        state.taskFilters[type] = value;
        document.querySelectorAll(`[data-task-filter="${type}"]`).forEach(btn => {
            btn.classList.toggle('active', btn.dataset.value === value);
        });
        loadTasks();
    }

    function updateAllTaskFilter(type, value) {
        if (state.allTaskFilters[type] === value) return;
        state.allTaskFilters[type] = value;
        document.querySelectorAll(`[data-all-task-filter="${type}"]`).forEach(btn => {
            btn.classList.toggle('active', btn.dataset.value === value);
        });
        loadAllTasks();
    }

    function switchTaskViewMode(mode) {
        if (state.taskViewMode === mode) return;
        state.taskViewMode = mode;

        // 更新切换按钮状态
        refs.taskViewToggleBtns.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.taskViewMode === mode);
        });

        // 切换面板显示
        refs.taskViewPanels.forEach(panel => {
            panel.hidden = panel.dataset.taskPanel !== mode;
        });

        // 根据视图模式渲染对应内容
        if (mode === 'calendar') {
            renderCalendar();
        } else if (mode === 'recent') {
            renderRecentTasks();
        }
    }

    function renderCalendar() {
        if (!refs.calendarDays || !refs.calendarMonthTitle) return;

        const year = state.calendarDate.getFullYear();
        const month = state.calendarDate.getMonth();
        const today = new Date();

        // 更新标题
        refs.calendarMonthTitle.textContent = `${year}年${month + 1}月`;

        // 获取当月第一天和最后一天
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);

        // 获取第一天是周几（周一为0）
        let startWeekday = firstDay.getDay() - 1;
        if (startWeekday < 0) startWeekday = 6;

        // 构建日期数组
        const days = [];

        // 上个月的日期
        const prevMonthLastDay = new Date(year, month, 0).getDate();
        for (let i = startWeekday - 1; i >= 0; i--) {
            days.push({
                date: new Date(year, month - 1, prevMonthLastDay - i),
                otherMonth: true
            });
        }

        // 当月日期
        for (let i = 1; i <= lastDay.getDate(); i++) {
            days.push({
                date: new Date(year, month, i),
                otherMonth: false
            });
        }

        // 下个月的日期（补齐6行）
        const remaining = 42 - days.length;
        for (let i = 1; i <= remaining; i++) {
            days.push({
                date: new Date(year, month + 1, i),
                otherMonth: true
            });
        }

        // 按日期分组任务
        const tasksByDate = {};
        state.tasks.forEach(task => {
            if (task.due_date) {
                const dateKey = task.due_date.split(' ')[0];
                if (!tasksByDate[dateKey]) {
                    tasksByDate[dateKey] = [];
                }
                tasksByDate[dateKey].push(task);
            }
        });

        // 渲染日历格子
        refs.calendarDays.innerHTML = days.map(({ date, otherMonth }) => {
            const dateKey = date.toISOString().split('T')[0];
            const dayTasks = tasksByDate[dateKey] || [];
            const isToday = date.toDateString() === today.toDateString();
            const isWeekend = date.getDay() === 0 || date.getDay() === 6;

            const classes = ['calendar-day'];
            if (otherMonth) classes.push('other-month');
            if (isToday) classes.push('today');
            if (isWeekend) classes.push('weekend');

            const tasksHtml = dayTasks.slice(0, 3).map(task => `
                <div class="calendar-task-item status-${task.status}" data-task-id="${task.id}" title="${escapeHtml(task.title)}">
                    ${escapeHtml(task.title)}
                </div>
            `).join('');

            const moreHtml = dayTasks.length > 3 
                ? `<div class="calendar-task-more">+${dayTasks.length - 3} 更多</div>` 
                : '';

            return `
                <div class="${classes.join(' ')}" data-date="${dateKey}">
                    <div class="day-number">${date.getDate()}</div>
                    <div class="calendar-tasks">
                        ${tasksHtml}
                        ${moreHtml}
                    </div>
                </div>
            `;
        }).join('');
    }

    function renderRecentTasks() {
        if (!refs.recentDateStrip || !refs.recentTaskList || !refs.recentDateLabel) return;

        const today = new Date();
        const baseDate = new Date(state.recentBaseDate);
        const selectedDate = new Date(state.recentSelectedDate);

        // 生成7天的日期（以基准日期为中心）
        const dates = [];
        const startOffset = -3;
        for (let i = 0; i < 7; i++) {
            const d = new Date(baseDate);
            d.setDate(baseDate.getDate() + startOffset + i);
            dates.push(d);
        }

        // 渲染日期条
        refs.recentDateStrip.innerHTML = dates.map(date => {
            const isToday = date.toDateString() === today.toDateString();
            const isSelected = date.toDateString() === selectedDate.toDateString();
            const dateKey = date.toISOString().split('T')[0];

            const classes = ['recent-date-item'];
            if (isToday) classes.push('today');
            if (isSelected) classes.push('active');

            return `<button class="${classes.join(' ')}" data-date="${dateKey}">${date.getDate()}</button>`;
        }).join('');

        // 更新日期标签
        const month = selectedDate.getMonth() + 1;
        const day = selectedDate.getDate();
        const isSelectedToday = selectedDate.toDateString() === today.toDateString();
        refs.recentDateLabel.textContent = `${month}月${day}日${isSelectedToday ? ' 今天' : ''}`;

        // 筛选选中日期的任务
        const selectedDateKey = selectedDate.toISOString().split('T')[0];
        const dayTasks = state.tasks.filter(task => {
            if (!task.due_date) return false;
            return task.due_date.split(' ')[0] === selectedDateKey;
        });

        // 渲染任务列表
        if (dayTasks.length === 0) {
            refs.recentTaskList.innerHTML = `
                <div class="recent-task-empty">
                    <svg class="recent-task-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                        <line x1="9" y1="16" x2="15" y2="16"/>
                    </svg>
                    <div class="recent-task-empty-text">这一天暂无任务</div>
                </div>
            `;
        } else {
            refs.recentTaskList.innerHTML = dayTasks.map(task => {
                const isCompleted = task.status === 'completed';
                return `
                    <div class="recent-task-item ${isCompleted ? 'completed' : ''}" data-task-id="${task.id}">
                        <div class="recent-task-checkbox ${isCompleted ? 'checked' : ''}" data-action="toggle-task-status">
                            ${isCompleted ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg>' : ''}
                        </div>
                        <span class="recent-task-title">${escapeHtml(task.title)}</span>
                    </div>
                `;
            }).join('');
        }
    }

    function openModal(name) {
        const modal = typeof name === 'string' ? refs.modals[name] : name;
        if (!modal) return;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    // ========== 新建OKR弹窗相关函数 ==========
    function openCreateOkrModal() {
        // 重置编辑状态
        state.editingContainerId = null;
        state.editingObjectiveId = null;
        
        ensureKrRow();
        updateOkrCreateCycleName();
        updateOBadgeNumber();
        state.pendingAlignments = [];
        if (refs.okrCreate.alignList) refs.okrCreate.alignList.innerHTML = '';
        
        // 重置表单
        if (refs.forms.container) {
            refs.forms.container.reset();
        }
        if (refs.krList) {
            refs.krList.innerHTML = '';
            ensureKrRow();
        }
        
        // 更新标题
        const modalTitle = refs.modals.okr?.querySelector('.okr-modal__header h3');
        if (modalTitle) modalTitle.textContent = '新建 OKR';
        
        openModal('okr');
    }

    function updateOkrCreateCycleName() {
        if (!refs.okrCreate.cycleName) return;
        const cycle = state.cycles.find(c => c.id == state.currentCycleId);
        if (cycle) {
            refs.okrCreate.cycleName.textContent = cycle.name;
        } else {
            refs.okrCreate.cycleName.textContent = '请选择周期';
        }
    }

    function updateOBadgeNumber() {
        if (!refs.okrCreate.oBadge) return;
        // 计算当前用户在当前周期的O数量
        const currentUserId = window.OKR_BOOTSTRAP?.user?.id;
        let oCount = 0;
        Object.values(state.containerDetails).forEach(detail => {
            if (detail.user_id == currentUserId) {
                oCount += (detail.objectives || []).length;
            }
        });
        refs.okrCreate.oBadge.textContent = `O${oCount + 1}`;
    }

    function openAlignModal() {
        if (!refs.alignListBody) return;
        renderAlignList();
        openModal('align');
    }

    function renderAlignList() {
        if (!refs.alignListBody) return;
        
        // 获取可对齐的OKR列表（排除当前用户自己的）
        const currentUserId = window.OKR_BOOTSTRAP?.user?.id;
        const alignableOkrs = [];
        
        Object.values(state.containerDetails).forEach(detail => {
            (detail.objectives || []).forEach(obj => {
                // 可以对齐到其他人的目标或KR
                alignableOkrs.push({
                    type: 'objective',
                    id: obj.id,
                    title: obj.title,
                    level: detail.level,
                    userName: detail.user_name,
                    keyResults: obj.key_results || []
                });
            });
        });

        if (alignableOkrs.length === 0) {
            refs.alignListBody.innerHTML = '<div class="empty-state">暂无可对齐的目标</div>';
            return;
        }

        const levelLabels = { company: '公司级', department: '部门级', personal: '个人级' };
        
        refs.alignListBody.innerHTML = alignableOkrs.map((okr, idx) => {
            const isChecked = state.pendingAlignments?.some(a => a.type === 'objective' && a.id === okr.id);
            return `
                <div class="align-okr-card">
                    <div class="align-okr-header">
                        <span class="align-okr-badge">O${idx + 1}</span>
                        <span class="align-okr-title">${escapeHtml(okr.title)}</span>
                        <div class="align-okr-checkbox ${isChecked ? 'checked' : ''}" data-align-type="objective" data-align-id="${okr.id}">
                            ${isChecked ? '✓' : ''}
                        </div>
                    </div>
                    <div class="align-okr-meta">
                        <span class="level-tag">${levelLabels[okr.level] || okr.level}</span>
                        <span class="user-info">
                            <span class="user-avatar-tiny">${(okr.userName || '?').charAt(0)}</span>
                            <span>${escapeHtml(okr.userName || '未知')}</span>
                        </span>
                    </div>
                    ${okr.keyResults.length > 0 ? `
                        <div class="align-kr-list">
                            ${okr.keyResults.map((kr, krIdx) => {
                                const krChecked = state.pendingAlignments?.some(a => a.type === 'kr' && a.id === kr.id);
                                return `
                                    <div class="align-kr-item">
                                        <span class="kr-label">KR${krIdx + 1}:</span>
                                        <span class="kr-title">${escapeHtml(kr.title)}</span>
                                        <div class="align-okr-checkbox ${krChecked ? 'checked' : ''}" data-align-type="kr" data-align-id="${kr.id}">
                                            ${krChecked ? '✓' : ''}
                                        </div>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    ` : ''}
                </div>
            `;
        }).join('');
    }

    function updateAlignCount() {
        const countEl = document.getElementById('okrAlignCount');
        if (!countEl) return;
        const checked = document.querySelectorAll('.align-okr-checkbox.checked').length;
        countEl.textContent = checked;
    }

    function confirmAlign() {
        state.pendingAlignments = [];
        document.querySelectorAll('.align-okr-checkbox.checked').forEach(checkbox => {
            state.pendingAlignments.push({
                type: checkbox.dataset.alignType,
                id: checkbox.dataset.alignId
            });
        });
        
        // 更新对齐列表显示
        renderAlignListInCreate();
        closeModal(refs.modals.align);
    }

    function renderAlignListInCreate() {
        if (!refs.okrCreate.alignList || !state.pendingAlignments) return;
        
        if (state.pendingAlignments.length === 0) {
            refs.okrCreate.alignList.innerHTML = '';
            return;
        }

        const items = state.pendingAlignments.map(align => {
            let title = '';
            let badge = '';
            
            if (align.type === 'objective') {
                Object.values(state.containerDetails).forEach(detail => {
                    (detail.objectives || []).forEach((obj, idx) => {
                        if (obj.id == align.id) {
                            title = obj.title;
                            badge = `O${idx + 1}`;
                        }
                    });
                });
            } else {
                Object.values(state.containerDetails).forEach(detail => {
                    (detail.objectives || []).forEach(obj => {
                        (obj.key_results || []).forEach((kr, idx) => {
                            if (kr.id == align.id) {
                                title = kr.title;
                                badge = `KR${idx + 1}`;
                            }
                        });
                    });
                });
            }

            return `
                <div class="okr-create-align-item">
                    <span class="align-badge">${badge}</span>
                    <span class="align-title">${escapeHtml(title)}</span>
                    <span class="remove-align" data-remove-align="${align.type}-${align.id}">×</span>
                </div>
            `;
        }).join('');

        refs.okrCreate.alignList.innerHTML = items;

        // 绑定移除事件
        refs.okrCreate.alignList.querySelectorAll('.remove-align').forEach(btn => {
            btn.addEventListener('click', () => {
                const [type, id] = btn.dataset.removeAlign.split('-');
                state.pendingAlignments = state.pendingAlignments.filter(a => !(a.type === type && a.id === id));
                renderAlignListInCreate();
            });
        });
    }

    function openUserSelectModal() {
        if (!refs.modals.userSelect) return;
        
        // 标记当前选中的用户
        const currentUserId = document.querySelector('input[name="user_id"]')?.value;
        refs.userSelectList?.querySelectorAll('.user-select-item').forEach(item => {
            if (item.dataset.userId === currentUserId) {
                item.classList.add('selected');
                item.querySelector('.check-icon').style.display = 'inline';
            } else {
                item.classList.remove('selected');
                item.querySelector('.check-icon').style.display = 'none';
            }
        });
        
        openModal('userSelect');
    }

    function openCreateTaskModal() {
        // 重置编辑状态
        state.editingTaskId = null;
        
        // 重置表单
        if (refs.forms.task) {
            refs.forms.task.reset();
        }
        
        // 更新标题
        const modalTitle = refs.modals.task?.querySelector('.okr-modal__header h3');
        if (modalTitle) modalTitle.textContent = '新建任务';
        
        openModal('task');
    }

    // KR设置相关
    let currentEditingKrRow = null;

    function openKrSettingsModal(krRow) {
        if (!krRow || !refs.modals.krSettings) return;
        currentEditingKrRow = krRow;
        
        // 填充当前KR的值
        const titleEl = document.getElementById('krSettingsTitle');
        const confidenceEl = document.getElementById('krSettingsConfidence');
        const confidenceValueEl = document.getElementById('krConfidenceValue');
        const weightValueEl = document.getElementById('krSettingsWeightValue');
        const unitValueEl = document.getElementById('krSettingsUnitValue');
        const startValueEl = document.getElementById('krSettingsStartValue');
        const targetValueEl = document.getElementById('krSettingsTargetValue');
        
        if (titleEl) titleEl.value = krRow.querySelector('textarea[name="kr_title[]"]')?.value || '';
        
        const confidence = krRow.querySelector('input[name="kr_confidence[]"]')?.value || '5';
        if (confidenceEl) {
            confidenceEl.value = confidence;
            const percent = ((confidence - 1) / 9) * 100;
            confidenceEl.style.background = `linear-gradient(to right, var(--primary-color) ${percent}%, var(--divider-color) ${percent}%)`;
        }
        if (confidenceValueEl) confidenceValueEl.textContent = confidence;
        
        if (weightValueEl) weightValueEl.textContent = krRow.querySelector('input[name="kr_weight[]"]')?.value || '25';
        if (unitValueEl) unitValueEl.textContent = krRow.querySelector('input[name="kr_unit[]"]')?.value || '%';
        if (startValueEl) startValueEl.value = '0';
        if (targetValueEl) targetValueEl.value = krRow.querySelector('input[name="kr_target[]"]')?.value || '100';
        
        openModal('krSettings');
    }

    function saveKrSettings() {
        if (!currentEditingKrRow) return;
        
        const titleEl = document.getElementById('krSettingsTitle');
        const confidenceEl = document.getElementById('krSettingsConfidence');
        const weightValueEl = document.getElementById('krSettingsWeightValue');
        const unitValueEl = document.getElementById('krSettingsUnitValue');
        const targetValueEl = document.getElementById('krSettingsTargetValue');
        
        // 更新KR行的值
        const titleInput = currentEditingKrRow.querySelector('textarea[name="kr_title[]"]');
        if (titleInput && titleEl) titleInput.value = titleEl.value;
        
        const confidenceInput = currentEditingKrRow.querySelector('input[name="kr_confidence[]"]');
        if (confidenceInput && confidenceEl) confidenceInput.value = confidenceEl.value;
        
        const weightInput = currentEditingKrRow.querySelector('input[name="kr_weight[]"]');
        if (weightInput && weightValueEl) weightInput.value = weightValueEl.textContent;
        
        const unitInput = currentEditingKrRow.querySelector('input[name="kr_unit[]"]');
        if (unitInput && unitValueEl) unitInput.value = unitValueEl.textContent;
        
        const targetInput = currentEditingKrRow.querySelector('input[name="kr_target[]"]');
        if (targetInput && targetValueEl) targetInput.value = targetValueEl.value;
        
        // 更新显示
        const confidenceDisplay = currentEditingKrRow.querySelector('.kr-confidence-display');
        if (confidenceDisplay && confidenceEl) {
            confidenceDisplay.textContent = `${confidenceEl.value * 10}%`;
        }
        
        closeModal(refs.modals.krSettings);
        currentEditingKrRow = null;
        showToast('设置已保存', 'success');
    }

    function openProgressModal() {
        if (!refs.progressList) return;
        const items = [];
        Object.values(state.containerDetails).forEach(detail => {
            (detail.objectives || []).forEach(obj => {
                (obj.key_results || []).forEach(kr => {
                    items.push(`
                        <div class="kr-item" data-kr-progress-row data-kr-id="${kr.id}">
                            <div><strong>${escapeHtml(kr.title)}</strong></div>
                            <input type="range" min="0" max="100" value="${kr.progress || 0}" />
                            <div>${kr.progress || 0}%</div>
                        </div>
                    `);
                });
            });
        });
        refs.progressList.innerHTML = items.join('') || '<div class="empty-state">暂无 KR 可更新</div>';
        openModal('progress');
    }

    // 任务选择相关
    let currentTaskSelectKrRow = null;
    let selectedTaskIds = new Set();

    async function openTaskSelectModal(krRow) {
        if (!krRow || !refs.modals.taskSelect) return;
        currentTaskSelectKrRow = krRow;
        selectedTaskIds.clear();

        // 获取已关联的任务ID
        const taskIdsInput = krRow.querySelector('.kr-task-ids-input');
        if (taskIdsInput && taskIdsInput.value) {
            const ids = taskIdsInput.value.split(',').filter(id => id.trim());
            ids.forEach(id => selectedTaskIds.add(id.trim()));
        }

        // 加载任务列表
        await loadTaskSelectList();
        openModal('taskSelect');
    }

    async function loadTaskSelectList(searchKeyword = '') {
        const listEl = document.getElementById('taskSelectList');
        if (!listEl) return;

        try {
            listEl.innerHTML = '<div class="task-select-loading">加载中...</div>';

            const params = {
                action: 'list',
                filter: 'my',
                status: ''
            };
            if (searchKeyword) {
                params.search = searchKeyword;
            }

            const response = await apiFetch('okr_task.php', { params });
            const tasks = response.data || [];

            if (tasks.length === 0) {
                listEl.innerHTML = '<div class="task-select-loading">暂无任务</div>';
                return;
            }

            listEl.innerHTML = tasks.map(task => {
                const isSelected = selectedTaskIds.has(String(task.id));
                const statusClass = task.status || 'pending';
                const statusText = {
                    pending: '待处理',
                    in_progress: '进行中',
                    completed: '已完成',
                    failed: '未达成'
                }[statusClass] || '待处理';

                return `
                    <div class="task-select-item ${isSelected ? 'selected' : ''}" data-task-id="${task.id}">
                        <div class="task-select-checkbox">
                            ${isSelected ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>' : ''}
                        </div>
                        <div class="task-select-content">
                            <div class="task-select-title">${escapeHtml(task.title || '')}</div>
                            <div class="task-select-meta">
                                <span class="task-select-status ${statusClass}">${statusText}</span>
                                ${task.executor_name ? `<span>负责人: ${escapeHtml(task.executor_name)}</span>` : ''}
                                ${task.due_date ? `<span>截止: ${task.due_date.split(' ')[0]}</span>` : ''}
                            </div>
                        </div>
                    </div>
                `;
            }).join('');

            // 绑定点击事件
            listEl.querySelectorAll('.task-select-item').forEach(item => {
                item.addEventListener('click', () => {
                    const taskId = item.dataset.taskId;
                    if (selectedTaskIds.has(taskId)) {
                        selectedTaskIds.delete(taskId);
                        item.classList.remove('selected');
                        item.querySelector('.task-select-checkbox').innerHTML = '';
                    } else {
                        selectedTaskIds.add(taskId);
                        item.classList.add('selected');
                        item.querySelector('.task-select-checkbox').innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>';
                    }
                });
            });

            // 绑定搜索
            const searchInput = document.getElementById('taskSelectSearch');
            if (searchInput) {
                let searchTimeout;
                searchInput.addEventListener('input', (e) => {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        loadTaskSelectList(e.target.value);
                    }, 300);
                });
            }
        } catch (error) {
            console.error('加载任务列表失败:', error);
            listEl.innerHTML = '<div class="task-select-loading">加载失败，请稍后重试</div>';
        }
    }

    function confirmTaskSelect() {
        if (!currentTaskSelectKrRow) return;

        const taskIdsInput = currentTaskSelectKrRow.querySelector('.kr-task-ids-input');
        if (taskIdsInput) {
            taskIdsInput.value = Array.from(selectedTaskIds).join(',');
        }

        // 更新显示
        const tasksCountEl = currentTaskSelectKrRow.querySelector('.kr-tasks-count');
        if (tasksCountEl) {
            const count = selectedTaskIds.size;
            if (count > 0) {
                tasksCountEl.textContent = `${count}个任务`;
                tasksCountEl.classList.add('has-tasks');
                tasksCountEl.setAttribute('data-count', count);
            } else {
                tasksCountEl.textContent = '关联任务';
                tasksCountEl.classList.remove('has-tasks');
            }
        }

        closeModal(refs.modals.taskSelect);
        currentTaskSelectKrRow = null;
        selectedTaskIds.clear();
    }

    function createTaskForKr() {
        // 保存当前KR行引用
        const krRow = currentTaskSelectKrRow;
        closeModal(refs.modals.taskSelect);
        openCreateTaskModal();
        // 标记这是为KR创建的任务，创建成功后自动关联
        state.pendingKrTask = krRow;
    }

    async function submitProgressUpdates() {
        const rows = Array.from(refs.progressList?.querySelectorAll('[data-kr-progress-row]') || []);
        if (!rows.length) {
            closeModal(refs.modals.progress);
            return;
        }
        for (const row of rows) {
            const id = row.dataset.krId;
            const value = row.querySelector('input').value;
            await apiFetch('okr_objective.php', {
                method: 'POST',
                body: { action: 'kr_update_progress', id, current_value: value }
            }).catch(() => null);
        }
        showToast('进度已更新');
        closeModal(refs.modals.progress);
        await loadContainers();
    }

    async function openCommentModal(dataset) {
        if (!dataset || !dataset.targetType) return;
        state.pendingComments = { type: dataset.targetType, id: dataset.targetId };
        refs.commentList.innerHTML = '<div class="empty-state">加载中...</div>';
        openModal('comment');
        try {
            const response = await apiFetch('okr_comment.php', {
                params: {
                    action: 'list',
                    target_type: dataset.targetType,
                    target_id: dataset.targetId
                }
            });
            renderComments(response.data || []);
        } catch (error) {
            renderComments([]);
        }
    }

    function renderComments(comments) {
        if (!refs.commentList) return;
        if (!comments.length) {
            refs.commentList.innerHTML = '<div class="empty-state">暂无评论</div>';
            return;
        }
        refs.commentList.innerHTML = comments.map(comment => `
            <div style="padding:10px 0;border-bottom:1px solid var(--border-color);">
                <div style="font-weight:600;">${escapeHtml(comment.user_name || '')}</div>
                <div style="font-size:13px;color:var(--text-secondary);">${new Date(comment.create_time * 1000).toLocaleString()}</div>
                <div style="margin-top:6px;">${escapeHtml(comment.content || '')}</div>
            </div>
        `).join('');
    }

    async function submitComment() {
        if (!state.pendingComments.type || !refs.forms.comment) return;
        const content = (refs.forms.comment.querySelector('textarea')?.value || '').trim();
        if (!content) {
            showToast('请输入评论');
            return;
        }
        await apiFetch('okr_comment.php', {
            method: 'POST',
            body: {
                action: 'save',
                target_type: state.pendingComments.type,
                target_id: state.pendingComments.id,
                content
            }
        });
        refs.forms.comment.reset();
        showToast('评论已发布');
        openCommentModal({ targetType: state.pendingComments.type, targetId: state.pendingComments.id });
    }

    function showToast(message) {
        if (!refs.toast) return;
        refs.toast.textContent = message;
        refs.toast.classList.add('show');
        clearTimeout(refs.toastTimer);
        refs.toastTimer = setTimeout(() => refs.toast.classList.remove('show'), 2200);
    }
    
    function showOkrConfirm(message, onConfirm) {
        if (typeof showConfirmModal === 'function') {
            showConfirmModal('确认操作', message, onConfirm);
        } else if (confirm(message)) {
            onConfirm();
        }
    }

    function statusLabel(value) {
        switch (value) {
            case 'pending': return '待处理';
            case 'in_progress': return '进行中';
            case 'completed': return '已完成';
            case 'failed': return '未达成';
            default: return value || '-';
        }
    }

    const API_ROOT = normalizeApiRoot(BOOT.apiBase);

    function normalizeApiRoot(base) {
        if (!base) {
            return window.location.origin;
        }
        if (/^https?:\/\//i.test(base)) {
            return base.replace(/\/+$/, '');
        }
        const origin = window.location.origin.replace(/\/+$/, '');
        const prefix = base.startsWith('/') ? '' : '/';
        return (origin + prefix + base).replace(/\/+$/, '');
    }

    async function apiFetch(path, { method = 'GET', params = {}, body = null } = {}) {
        const url = new URL(path, API_ROOT + '/');
        Object.entries(params).forEach(([key, val]) => {
            if (val !== undefined && val !== null && val !== '') {
                url.searchParams.append(key, val);
            }
        });
        const options = { method, credentials: 'same-origin' };
        if (method !== 'GET') {
            const payload = body instanceof FormData ? body : new URLSearchParams(body);
            options.body = payload;
        }
        const response = await fetch(url.toString(), options);
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.success === false) {
            throw new Error(data.message || '请求失败');
        }
        return data;
    }

    function escapeHtml(str) {
        if (str === undefined || str === null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
})();

