import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { FolderSync, Loader2, CheckCircle, XCircle, RefreshCw } from 'lucide-react';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { toast } from '@/hooks/use-toast';
import { http } from '@/lib/http';
import { 
  isRememberLoginEnabled, 
  setRememberLogin, 
  getRememberedUsername, 
  setRememberedUsername,
  saveCurrentStorageSuffix,
  getInstanceId
} from '@/lib/instanceId';
import type { LoginResponse } from '@/types';
import { getVersion } from '@tauri-apps/api/app';

export default function LoginPage() {
  const navigate = useNavigate();
  const setAuth = useAuthStore((state) => state.setAuth);
  const serverUrl = useSettingsStore((state) => state.serverUrl);
  const setServerUrl = useSettingsStore((state) => state.setServerUrl);

  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [server, setServer] = useState(serverUrl || '');
  const [rememberLogin, setRememberLoginState] = useState(isRememberLoginEnabled());
  const [rememberUsername, setRememberUsername] = useState(true);
  const [appVersion, setAppVersion] = useState('');
  
  // 初始化：加载记住的用户名和版本号
  useEffect(() => {
    const savedUsername = getRememberedUsername();
    if (savedUsername) {
      setUsername(savedUsername);
    }
    getVersion().then(v => setAppVersion(v)).catch(() => setAppVersion(''));
  }, []);
  const [loading, setLoading] = useState(false);
  const [testing, setTesting] = useState(false);
  const [connectionStatus, setConnectionStatus] = useState<'idle' | 'success' | 'error'>('idle');
  const [connectionMessage, setConnectionMessage] = useState('');

  // 服务器地址变化时自动保存
  const handleServerChange = (value: string) => {
    setServer(value);
    setConnectionStatus('idle');
    // 立即保存到 store（无论是否正确）
    if (value.trim()) {
      setServerUrl(value.trim());
    }
  };

  // 测试服务器连接
  const handleTestConnection = async () => {
    if (!server.trim()) {
      toast({ title: '请输入服务器地址', variant: 'destructive' });
      return;
    }

    setTesting(true);
    setConnectionStatus('idle');
    
    const result = await http.testConnection(server.trim());
    
    setTesting(false);
    setConnectionStatus(result.success ? 'success' : 'error');
    setConnectionMessage(result.message);
    
    if (!result.success) {
      toast({ title: '连接测试', description: result.message, variant: 'destructive' });
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!server.trim()) {
      toast({ title: '请输入服务器地址', variant: 'destructive' });
      return;
    }
    if (!username.trim() || !password.trim()) {
      toast({ title: '请输入用户名和密码', variant: 'destructive' });
      return;
    }

    setLoading(true);

    try {
      // 先测试连接
      const connResult = await http.testConnection(server.trim());
      if (!connResult.success) {
        toast({
          title: '无法连接服务器',
          description: connResult.message,
          variant: 'destructive',
        });
        setConnectionStatus('error');
        setConnectionMessage(connResult.message);
        return;
      }

      const url = `${server.trim()}/api/desktop_login.php`;
      const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: username.trim(), password }),
      });

      const data: LoginResponse = await response.json();

      if (!data.success || !data.data) {
        toast({
          title: '登录失败',
          description: data.error?.message || '用户名或密码错误',
          variant: 'destructive',
        });
        return;
      }

      // 保存记住登录设置
      setRememberLogin(rememberLogin);
      if (rememberUsername) {
        setRememberedUsername(username.trim());
      } else {
        setRememberedUsername('');
      }
      
      // 保存当前使用的存储后缀，让悬浮窗能使用相同的 key
      const currentSuffix = rememberLogin ? '' : getInstanceId();
      saveCurrentStorageSuffix(currentSuffix);
      
      setServerUrl(server.trim());
      setAuth(data.data.token, data.data.user, data.data.expire_at);
      
      // 登录后立即获取权限
      await useAuthStore.getState().fetchPermissions(server.trim());

      toast({ title: '登录成功', variant: 'success' });
      navigate('/project-kanban');
    } catch (error) {
      console.error('[SYNC_DEBUG] 登录失败:', error);
      const errorMessage = error instanceof Error ? error.message : '未知错误';
      toast({
        title: '登录失败',
        description: errorMessage.includes('fetch') ? '无法连接服务器，请检查网络' : errorMessage,
        variant: 'destructive',
      });
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-background-light p-4">
      <div className="w-full max-w-sm">
        <div className="bg-surface-light rounded-xl shadow-lg p-8">
          {/* Logo */}
          <div className="flex items-center justify-center mb-8">
            <FolderSync className="w-10 h-10 text-primary mr-3" />
            <span className="text-xl font-semibold text-text-main">项目管理工具</span>
          </div>

          {/* Form */}
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-text-secondary mb-1">
                服务器地址
              </label>
              <div className="flex gap-2">
                <input
                  type="url"
                  value={server}
                  onChange={(e) => handleServerChange(e.target.value)}
                  onBlur={() => {
                    // 失去焦点时也保存
                    if (server.trim()) {
                      setServerUrl(server.trim());
                    }
                  }}
                  placeholder="http://your-server-ip"
                  className="flex-1 px-3 py-2 border border-border-light rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary/50"
                />
                <button
                  type="button"
                  onClick={handleTestConnection}
                  disabled={testing || !server.trim()}
                  className="px-3 py-2 border border-border-light rounded-lg text-sm hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed flex items-center"
                  title="测试连接"
                >
                  {testing ? (
                    <Loader2 className="w-4 h-4 animate-spin" />
                  ) : connectionStatus === 'success' ? (
                    <CheckCircle className="w-4 h-4 text-green-500" />
                  ) : connectionStatus === 'error' ? (
                    <XCircle className="w-4 h-4 text-red-500" />
                  ) : (
                    <RefreshCw className="w-4 h-4" />
                  )}
                </button>
              </div>
              {connectionMessage && (
                <p className={`mt-1 text-xs ${connectionStatus === 'success' ? 'text-green-600' : 'text-red-500'}`}>
                  {connectionMessage}
                </p>
              )}
            </div>

            <div>
              <label className="block text-sm font-medium text-text-secondary mb-1">
                用户名
              </label>
              <input
                type="text"
                value={username}
                onChange={(e) => setUsername(e.target.value)}
                placeholder="请输入用户名"
                className="w-full px-3 py-2 border border-border-light rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary/50"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-text-secondary mb-1">
                密码
              </label>
              <input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="请输入密码"
                className="w-full px-3 py-2 border border-border-light rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary/50"
              />
            </div>

            {/* 记住登录选项 */}
            <div className="space-y-2">
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={rememberUsername}
                  onChange={(e) => setRememberUsername(e.target.checked)}
                  className="w-4 h-4 rounded border-gray-300 text-primary focus:ring-primary"
                />
                <span className="text-sm text-text-secondary">记住账号</span>
              </label>
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={rememberLogin}
                  onChange={(e) => setRememberLoginState(e.target.checked)}
                  className="w-4 h-4 rounded border-gray-300 text-primary focus:ring-primary"
                />
                <span className="text-sm text-text-secondary">下次自动登录</span>
                <span className="text-xs text-gray-400">(关闭后保留缓存)</span>
              </label>
            </div>

            <button
              type="submit"
              disabled={loading}
              className="w-full py-2.5 bg-primary text-white rounded-lg font-medium hover:bg-primary/90 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center"
            >
              {loading ? (
                <>
                  <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                  登录中...
                </>
              ) : (
                '登录'
              )}
            </button>
          </form>
        </div>

        <p className="mt-4 text-center text-xs text-text-secondary">
          项目管理工具客户端 {appVersion ? `v${appVersion}` : ''}
        </p>
      </div>
    </div>
  );
}
