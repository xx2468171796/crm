// 移动端优化 - Bootstrap 5 + jQuery
// Alone 呕血制作
(function($) {
    'use strict';
    
    // 检测是否为移动设备
    const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    const isSmallScreen = $(window).width() <= 768;
    
    // 移动端优化配置
    const mobileConfig = {
        enableTouchOptimization: true,
        enableSmoothScroll: true,
        enableSwipeGestures: true,
        tabSwitchAnimation: true
    };
    
    // 初始化移动端优化
    function initMobileOptimization() {
        if (!isMobile && !isSmallScreen) return;
        
        console.log('%c📱 移动端优化已启用', 'color: #0d6efd; font-weight: bold;');
        
        // 1. 优化触摸反馈
        optimizeTouchFeedback();
        
        // 2. 优化侧边栏为顶部标签栏
        optimizeSidebar();
        
        // 3. 优化表单输入
        optimizeFormInputs();
        
        // 4. 优化滚动性能
        optimizeScrolling();
        
        // 5. 添加滑动手势支持
        if (mobileConfig.enableSwipeGestures) {
            addSwipeGestures();
        }
        
        // 6. 优化按钮点击区域
        optimizeClickAreas();
        
        // 7. 添加加载动画
        addLoadingAnimation();
    }
    
    // 1. 优化触摸反馈
    function optimizeTouchFeedback() {
        // 为所有可点击元素添加触摸反馈
        $(document).on('touchstart', '.btn, .nav-link, a, button, .form-check-label', function() {
            $(this).addClass('touch-active');
        }).on('touchend touchcancel', '.btn, .nav-link, a, button, .form-check-label', function() {
            const $this = $(this);
            setTimeout(() => $this.removeClass('touch-active'), 150);
        });
        
        // 添加触摸反馈样式
        $('<style>')
            .text(`
                .touch-active {
                    opacity: 0.7 !important;
                    transform: scale(0.98) !important;
                    transition: all 0.15s ease !important;
                }
            `)
            .appendTo('head');
    }
    
    // 2. 优化侧边栏为顶部标签栏
    function optimizeSidebar() {
        const $sidebar = $('.sidebar');
        const $navLinks = $sidebar.find('.nav-link');
        
        if ($sidebar.length === 0) return;
        
        // 添加 Bootstrap 类
        $sidebar.addClass('d-flex flex-row overflow-auto');
        
        // 优化标签切换动画
        if (mobileConfig.tabSwitchAnimation) {
            $navLinks.on('click', function(e) {
                const $this = $(this);
                const tabId = $this.data('tab');
                
                // 添加切换动画
                $('.tab-content-section.active')
                    .removeClass('active')
                    .fadeOut(200, function() {
                        $('#tab-' + tabId)
                            .addClass('active')
                            .hide()
                            .fadeIn(300);
                    });
                
                // 滚动到激活的标签
                scrollToActiveTab($this);
            });
        }
        
        // 初始滚动到激活的标签
        const $activeTab = $navLinks.filter('.active');
        if ($activeTab.length > 0) {
            setTimeout(() => scrollToActiveTab($activeTab), 300);
        }
    }
    
    // 滚动到激活的标签
    function scrollToActiveTab($tab) {
        const $sidebar = $('.sidebar');
        if ($sidebar.length === 0 || $tab.length === 0) return;
        
        const tabOffset = $tab.position().left;
        const sidebarWidth = $sidebar.width();
        const tabWidth = $tab.outerWidth();
        
        $sidebar.animate({
            scrollLeft: tabOffset - (sidebarWidth / 2) + (tabWidth / 2)
        }, 300, 'swing');
    }
    
    // 3. 优化表单输入
    function optimizeFormInputs() {
        // 防止 iOS 自动缩放
        $('input, textarea, select').attr('autocomplete', 'off');
        
        // 输入框获得焦点时滚动到视图
        $('input, textarea, select').on('focus', function() {
            const $this = $(this);
            setTimeout(() => {
                $this[0].scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            }, 300);
        });
        
        // 优化选择框显示
        $('.field-options label').each(function() {
            const $label = $(this);
            const $input = $label.find('input[type="radio"], input[type="checkbox"]');
            
            if ($input.length > 0) {
                // 添加 Bootstrap 按钮样式
                $label.addClass('btn btn-outline-secondary btn-sm mb-2');
                
                // 选中状态
                if ($input.is(':checked')) {
                    $label.removeClass('btn-outline-secondary').addClass('btn-primary');
                }
                
                // 监听变化
                $input.on('change', function() {
                    const $parentOptions = $(this).closest('.field-options');
                    $parentOptions.find('label').removeClass('btn-primary').addClass('btn-outline-secondary');
                    if ($(this).is(':checked')) {
                        $(this).closest('label').removeClass('btn-outline-secondary').addClass('btn-primary');
                    }
                });
            }
        });
    }
    
    // 4. 优化滚动性能
    function optimizeScrolling() {
        if (!mobileConfig.enableSmoothScroll) return;
        
        // 平滑滚动
        $('html').css({
            'scroll-behavior': 'smooth',
            '-webkit-overflow-scrolling': 'touch'
        });
        
        // 优化内容区域滚动
        $('.content-area').css({
            '-webkit-overflow-scrolling': 'touch',
            'overscroll-behavior': 'contain'
        });
    }
    
    // 5. 添加滑动手势支持
    function addSwipeGestures() {
        let touchStartX = 0;
        let touchEndX = 0;
        const minSwipeDistance = 50;
        
        const $sidebar = $('.sidebar');
        const $navLinks = $sidebar.find('.nav-link');
        
        $('.content-area').on('touchstart', function(e) {
            touchStartX = e.changedTouches[0].screenX;
        });
        
        $('.content-area').on('touchend', function(e) {
            touchEndX = e.changedTouches[0].screenX;
            handleSwipe();
        });
        
        function handleSwipe() {
            const swipeDistance = touchEndX - touchStartX;
            
            if (Math.abs(swipeDistance) < minSwipeDistance) return;
            
            const $activeTab = $navLinks.filter('.active');
            const currentIndex = $navLinks.index($activeTab);
            
            if (swipeDistance > 0 && currentIndex > 0) {
                // 向右滑动 - 上一个标签
                $navLinks.eq(currentIndex - 1).trigger('click');
            } else if (swipeDistance < 0 && currentIndex < $navLinks.length - 1) {
                // 向左滑动 - 下一个标签
                $navLinks.eq(currentIndex + 1).trigger('click');
            }
        }
    }
    
    // 6. 优化按钮点击区域
    function optimizeClickAreas() {
        // 确保所有按钮有足够的点击区域（44x44px iOS 标准）
        $('.btn, .nav-link, a, button').each(function() {
            const $this = $(this);
            const height = $this.outerHeight();
            
            if (height < 44) {
                $this.css({
                    'min-height': '44px',
                    'display': 'inline-flex',
                    'align-items': 'center',
                    'justify-content': 'center'
                });
            }
        });
    }
    
    // 7. 添加加载动画
    function addLoadingAnimation() {
        // 为 AJAX 请求添加加载指示器
        $(document).on('ajaxStart', function() {
            if ($('#mobile-loading-indicator').length === 0) {
                $('<div>')
                    .attr('id', 'mobile-loading-indicator')
                    .addClass('position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center')
                    .css({
                        'background': 'rgba(0,0,0,0.3)',
                        'z-index': 99999
                    })
                    .html(`
                        <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                            <span class="visually-hidden">加载中...</span>
                        </div>
                    `)
                    .appendTo('body')
                    .hide()
                    .fadeIn(200);
            }
        }).on('ajaxStop', function() {
            $('#mobile-loading-indicator').fadeOut(200, function() {
                $(this).remove();
            });
        });
    }
    
    // 窗口大小改变时重新初始化
    let resizeTimer;
    $(window).on('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            const newIsSmallScreen = $(window).width() <= 768;
            if (newIsSmallScreen !== isSmallScreen) {
                location.reload();
            }
        }, 250);
    });
    
    // 页面加载完成后初始化
    $(document).ready(function() {
        initMobileOptimization();
        
        // 添加移动端标识类
        if (isMobile || isSmallScreen) {
            $('body').addClass('mobile-optimized');
        }
        
        console.log('%c✅ 移动端优化完成', 'color: #198754; font-weight: bold;');
        console.log('%c💖 Alone 呕血制作', 'color: #dc3545; font-style: italic;');
    });
    
})(jQuery);
