// 全局 jQuery + AJAX 配置

// jQuery AJAX 全局设置
$.ajaxSetup({
    timeout: 30000, // 30秒超时
    cache: false,
    error: function(xhr, status, error) {
        console.error('AJAX错误:', status, error);
        if (xhr.status === 401) {
            showAlertModal('会话已过期，请重新登录', 'warning', function() {
                window.location.href = '../public/login.php';
            });
        } else if (xhr.status === 403) {
            showAlertModal('无权限操作', 'error');
        } else if (xhr.status === 500) {
            showAlertModal('服务器错误，请稍后重试', 'error');
        } else if (status === 'timeout') {
            showAlertModal('请求超时，请检查网络连接', 'error');
        }
    }
});

// 全局 AJAX 辅助函数
window.ajaxPost = function(url, data, successCallback, errorCallback) {
    $.ajax({
        url: url,
        type: 'POST',
        data: data,
        dataType: 'json',
        success: function(response) {
            if (successCallback) {
                successCallback(response);
            }
        },
        error: function(xhr, status, error) {
            if (errorCallback) {
                errorCallback(xhr, status, error);
            }
        }
    });
};

window.ajaxGet = function(url, data, successCallback, errorCallback) {
    $.ajax({
        url: url,
        type: 'GET',
        data: data,
        dataType: 'json',
        success: function(response) {
            if (successCallback) {
                successCallback(response);
            }
        },
        error: function(xhr, status, error) {
            if (errorCallback) {
                errorCallback(xhr, status, error);
            }
        }
    });
};

// 显示加载中状态
window.showLoading = function(message) {
    message = message || '加载中...';
    if ($('#globalLoading').length === 0) {
        $('body').append(`
            <div id="globalLoading" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
                 background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; 
                 justify-content: center;">
                <div style="background: white; padding: 20px 40px; border-radius: 8px; text-align: center;">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="mt-2">${message}</div>
                </div>
            </div>
        `);
    }
};

// 隐藏加载中状态
window.hideLoading = function() {
    $('#globalLoading').remove();
};
