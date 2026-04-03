// 全局点击特效 - Alone呕血制作
// 使用 Bootstrap 5 + jQuery 优化，防止特效堆叠
(function($) {
    'use strict';
    
    // 配置选项
    const config = {
        maxEffects: 5,           // 最大同时显示的特效数量
        throttleDelay: 100,      // 节流延迟（毫秒）
        excludeSelectors: [      // 排除的元素选择器
            'input', 'textarea', 'select', 'button', 
            '.btn', '.form-control', '.modal', 'a'
        ]
    };
    
    // 点击特效文字数组 - 多种主题
    const themes = {
        love: ['❤', '💙', '💚', '💛', '💜', '🧡', '💖', '💗', '💓', '💕', '💞', '💝'],
        star: ['✨', '⭐', '🌟', '💫', '⚡', '🔥', '💥'],
        party: ['🎉', '🎊', '🎈', '🎁', '🎀', '🎆', '🎇'],
        emoji: ['😊', '😄', '😍', '🥰', '😎', '🤩', '✌️', '👍', '💪', '🙌'],
        nature: ['🌸', '🌺', '🌻', '🌷', '🌹', '🍀', '🌈', '☀️', '🌙', '⭐']
    };
    
    // 合并所有主题
    const allTexts = [...themes.love, ...themes.star, ...themes.party];
    
    // Bootstrap 5 颜色主题
    const colors = [
        '#0d6efd', '#6610f2', '#6f42c1', '#d63384', 
        '#dc3545', '#fd7e14', '#ffc107', '#198754',
        '#20c997', '#0dcaf0', '#FF6B6B', '#4ECDC4',
        '#45B7D1', '#F7DC6F', '#BB8FCE', '#52B788'
    ];
    
    // 动画样式数组
    const animations = [
        { transform: 'translateY(-80px) scale(1.5) rotate(15deg)', duration: 800 },
        { transform: 'translateY(-70px) scale(1.8) rotate(-15deg)', duration: 900 },
        { transform: 'translateY(-90px) scale(1.3) rotate(360deg)', duration: 1000 },
        { transform: 'translateY(-75px) translateX(25px) scale(1.6)', duration: 850 },
        { transform: 'translateY(-75px) translateX(-25px) scale(1.6)', duration: 850 }
    ];
    
    // 当前活动的特效数量
    let activeEffects = 0;
    
    // 节流标志
    let isThrottled = false;
    
    // 创建特效容器（使用 Bootstrap 的定位类）
    const $effectContainer = $('<div>')
        .attr('id', 'click-effect-container')
        .css({
            position: 'fixed',
            top: 0,
            left: 0,
            width: '100%',
            height: '100%',
            pointerEvents: 'none',
            zIndex: 99999,
            overflow: 'hidden'
        })
        .appendTo('body');
    
    // 检查是否应该排除该元素
    function shouldExclude(target) {
        return config.excludeSelectors.some(selector => {
            return $(target).is(selector) || $(target).closest(selector).length > 0;
        });
    }
    
    // 创建点击特效
    function createClickEffect(e) {
        // 节流控制
        if (isThrottled) return;
        
        // 检查是否排除
        if (shouldExclude(e.target)) return;
        
        // 限制最大特效数量
        if (activeEffects >= config.maxEffects) return;
        
        // 设置节流
        isThrottled = true;
        setTimeout(() => { isThrottled = false; }, config.throttleDelay);
        
        // 增加活动特效计数
        activeEffects++;
        
        // 随机选择文字、颜色和动画
        const text = allTexts[Math.floor(Math.random() * allTexts.length)];
        const color = colors[Math.floor(Math.random() * colors.length)];
        const animation = animations[Math.floor(Math.random() * animations.length)];
        
        // 使用 jQuery 创建特效元素
        const $effect = $('<div>')
            .addClass('click-effect-item')
            .text(text)
            .css({
                position: 'absolute',
                left: e.clientX - 12 + 'px',
                top: e.clientY - 12 + 'px',
                fontSize: '24px',
                fontWeight: 'bold',
                color: color,
                pointerEvents: 'none',
                userSelect: 'none',
                transition: `all ${animation.duration}ms cubic-bezier(0.25, 0.46, 0.45, 0.94)`,
                opacity: 1,
                textShadow: `0 0 10px ${color}40, 0 2px 4px rgba(0,0,0,0.3)`,
                transform: 'scale(1)',
                willChange: 'transform, opacity'
            })
            .appendTo($effectContainer);
        
        // 使用 requestAnimationFrame 触发动画
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                $effect.css({
                    transform: animation.transform,
                    opacity: 0
                });
            });
        });
        
        // 动画结束后移除元素并减少计数
        setTimeout(() => {
            $effect.fadeOut(200, function() {
                $(this).remove();
                activeEffects--;
            });
        }, animation.duration);
    }
    
    // 使用 jQuery 绑定点击事件（支持动态元素）
    $(document).on('click', function(e) {
        createClickEffect(e);
    });
    
    // 清理函数（页面卸载时）
    $(window).on('beforeunload', function() {
        $effectContainer.remove();
    });
    
    // 添加版权信息到控制台
    console.log('%c✨ 点击特效已加载 (Bootstrap 5 + jQuery 优化版) ✨', 
        'color: #0d6efd; font-size: 16px; font-weight: bold; text-shadow: 2px 2px 4px rgba(0,0,0,0.2);');
    console.log('%c💖 Alone 呕血制作 💖', 
        'color: #dc3545; font-size: 14px; font-style: italic; font-weight: bold;');
    console.log('%c⚡ 特性：防堆叠 | 节流控制 | 性能优化', 
        'color: #198754; font-size: 12px;');
    
})(jQuery);
