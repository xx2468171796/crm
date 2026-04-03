/**
 * 实例 ID 管理
 * 支持两种模式：
 * 1. 记住登录模式：使用固定 key，数据持久化
 * 2. 临时登录模式：使用动态 key，支持多账号
 */

const INSTANCE_ID_KEY = 'app_instance_id';
const REMEMBER_LOGIN_KEY = 'remember_login';
const REMEMBERED_USERNAME_KEY = 'remembered_username';
const CURRENT_STORAGE_SUFFIX_KEY = 'current_storage_suffix'; // 主窗口保存当前使用的存储后缀

// 检查是否启用"记住登录"模式
export function isRememberLoginEnabled(): boolean {
  return localStorage.getItem(REMEMBER_LOGIN_KEY) === 'true';
}

// 设置"记住登录"模式
export function setRememberLogin(enabled: boolean): void {
  if (enabled) {
    localStorage.setItem(REMEMBER_LOGIN_KEY, 'true');
    sessionStorage.removeItem(INSTANCE_ID_KEY);
  } else {
    localStorage.removeItem(REMEMBER_LOGIN_KEY);
  }
}

// 获取/设置记住的用户名
export function getRememberedUsername(): string {
  return localStorage.getItem(REMEMBERED_USERNAME_KEY) || '';
}

export function setRememberedUsername(username: string): void {
  if (username) {
    localStorage.setItem(REMEMBERED_USERNAME_KEY, username);
  } else {
    localStorage.removeItem(REMEMBERED_USERNAME_KEY);
  }
}

// 保存当前使用的存储后缀（主窗口登录时调用）
export function saveCurrentStorageSuffix(suffix: string): void {
  localStorage.setItem(CURRENT_STORAGE_SUFFIX_KEY, suffix);
  console.log('[InstanceId] 保存存储后缀:', suffix);
}

// 获取当前使用的存储后缀（悬浮窗读取）
export function getCurrentStorageSuffix(): string {
  return localStorage.getItem(CURRENT_STORAGE_SUFFIX_KEY) || '';
}

// 获取当前实例 ID
// 简化设计：始终返回空，使用固定的存储 key
// 这样主窗口和悬浮窗可以共享数据
export function getInstanceId(): string {
  console.log('[InstanceId] 使用固定 key 模式（简化设计）');
  return '';
}

// 获取带实例 ID 的存储 key
export function getStorageKey(baseKey: string): string {
  const instanceId = getInstanceId();
  if (!instanceId) {
    return baseKey; // 记住登录模式
  }
  return `${baseKey}_${instanceId}`; // 临时登录模式
}

// 清理当前实例的所有存储数据
export function clearInstanceStorage(): void {
  const instanceId = getInstanceId();
  
  if (!instanceId) {
    // 记住登录模式：清除固定 key
    localStorage.removeItem('auth-storage');
    localStorage.removeItem('settings-storage');
    localStorage.removeItem('permissions-storage');
    return;
  }
  
  // 临时登录模式：清除带实例 ID 的 key
  const keysToRemove: string[] = [];
  for (let i = 0; i < localStorage.length; i++) {
    const key = localStorage.key(i);
    if (key && key.endsWith(`_${instanceId}`)) {
      keysToRemove.push(key);
    }
  }
  keysToRemove.forEach(key => localStorage.removeItem(key));
}
