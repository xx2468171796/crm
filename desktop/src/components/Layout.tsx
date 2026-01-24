import { useEffect, useState } from 'react';
import { Outlet, NavLink, useNavigate } from 'react-router-dom';
import { LayoutDashboard, Kanban, ListTodo, FileCheck, FolderSync, DollarSign, Settings, LogOut, User, ClipboardList, PanelRightOpen, Users, FolderKanban, Pin, PinOff, HardDrive } from 'lucide-react';
import { useAuthStore } from '@/stores/auth';
import { cn, canViewFinance } from '@/lib/utils';
import { onEvent, EVENTS } from '@/lib/windowEvents';
import { useAutoSync } from '@/hooks/use-auto-sync';

const isTauri = typeof window !== 'undefined' && '__TAURI__' in window;


const navItems = [
  { to: '/dashboard', icon: LayoutDashboard, label: '总览' },
  { to: '/project-kanban', icon: Kanban, label: '项目看板' },
  { to: '/forms', icon: ClipboardList, label: '表单处理' },
  { to: '/tasks', icon: ListTodo, label: '任务管理' },
  { to: '/team-tasks', icon: Users, label: '团队任务', managerOnly: true },
  { to: '/team-projects', icon: FolderKanban, label: '团队项目', managerOnly: true },
  { to: '/tech-commission', icon: DollarSign, label: '技术提成', managerOnly: true },
  { to: '/approval', icon: FileCheck, label: '作品审批', managerOnly: true },
  { to: '/file-logs', icon: FolderSync, label: '文件日志' },
  { to: '/file-sync', icon: FolderSync, label: '文件同步' },
  { to: '/personal-drive', icon: HardDrive, label: '我的网盘' },
  { to: '/finance', icon: DollarSign, label: '我的财务', financeOnly: true },
  { to: '/settings', icon: Settings, label: '设置' },
];

export default function Layout() {
  const user = useAuthStore((state) => state.user);
  const logout = useAuthStore((state) => state.logout);
  const navigate = useNavigate();
  const [isAlwaysOnTop, setIsAlwaysOnTop] = useState(false);
  
  const isManager = ['admin', 'super_admin', 'manager', 'tech_manager', 'design_manager'].includes(user?.role || '');
  const showFinance = canViewFinance(user?.role);
  
  // 启动自动同步（后台扫描和上传）
  useAutoSync();

  // 切换主窗口置顶状态
  const toggleAlwaysOnTop = async () => {
    if (!isTauri) return;
    try {
      const { getCurrentWindow } = await import('@tauri-apps/api/window');
      const win = getCurrentWindow();
      const newValue = !isAlwaysOnTop;
      await win.setAlwaysOnTop(newValue);
      setIsAlwaysOnTop(newValue);
    } catch (e) {
      console.error('切换置顶状态失败:', e);
    }
  };

  // 监听悬浮窗事件
  useEffect(() => {
    let unlistenTask: (() => void) | null = null;
    let unlistenProject: (() => void) | null = null;
    
    const setupListeners = async () => {
      // 监听打开任务详情事件
      unlistenTask = await onEvent<{ taskId: number }>(EVENTS.OPEN_TASK_DETAIL, (payload) => {
        navigate(`/tasks?taskId=${payload.taskId}`);
      });
      
      // 监听打开项目详情事件
      unlistenProject = await onEvent<{ projectId: number }>(EVENTS.OPEN_PROJECT_DETAIL, (payload) => {
        console.log('[Layout] 收到打开项目详情事件:', payload);
        const targetPath = `/project/${payload.projectId}`;
        console.log('[Layout] 导航到:', targetPath);
        // 如果当前已在项目详情页，先导航到其他页面再跳回，强制重新加载
        if (window.location.pathname.startsWith('/project/')) {
          navigate('/project-kanban', { replace: true });
          setTimeout(() => navigate(targetPath, { replace: true }), 50);
        } else {
          navigate(targetPath);
        }
      });
    };
    
    setupListeners();
    return () => {
      if (unlistenTask) unlistenTask();
      if (unlistenProject) unlistenProject();
    };
  }, [navigate]);

  const handleLogout = () => {
    logout();
    navigate('/login');
  };

  // 打开悬浮窗
  const handleOpenFloating = async () => {
    if (!isTauri) return;
    try {
      const { WebviewWindow } = await import('@tauri-apps/api/webviewWindow');
      const floatingWin = await WebviewWindow.getByLabel('floating');
      if (floatingWin) {
        await floatingWin.show();
        await floatingWin.setFocus();
      }
    } catch (e) {
      console.error('打开悬浮窗失败:', e);
    }
  };

  return (
    <div className="flex h-screen bg-background-light">
      {/* Sidebar */}
      <aside className="w-56 flex flex-col bg-surface-light border-r border-border-light">
        {/* Logo */}
        <div className="h-14 flex items-center justify-between px-4 border-b border-border-light">
          <div className="flex items-center">
            <FolderSync className="w-6 h-6 text-primary mr-2" />
            <span className="font-semibold text-text-main">项目管理工具</span>
          </div>
          {/* 窗口控制按钮 */}
          {isTauri && (
            <div className="flex items-center gap-1">
              <button
                onClick={toggleAlwaysOnTop}
                className={cn(
                  "p-1.5 rounded transition-colors",
                  isAlwaysOnTop 
                    ? "bg-primary/20 text-primary" 
                    : "hover:bg-primary/10 text-text-secondary hover:text-primary"
                )}
                title={isAlwaysOnTop ? "取消置顶" : "窗口置顶"}
              >
                {isAlwaysOnTop ? <Pin className="w-4 h-4" /> : <PinOff className="w-4 h-4" />}
              </button>
              <button
                onClick={handleOpenFloating}
                className="p-1.5 rounded hover:bg-primary/10 text-text-secondary hover:text-primary transition-colors"
                title="打开悬浮窗"
              >
                <PanelRightOpen className="w-4 h-4" />
              </button>
            </div>
          )}
        </div>

        {/* Nav */}
        <nav className="flex-1 py-4">
          {navItems
            .filter((item) => {
              // 管理员专属菜单
              if ('managerOnly' in item && item.managerOnly && !isManager) return false;
              // 财务菜单（design_manager不可见）
              if ('financeOnly' in item && item.financeOnly && !showFinance) return false;
              return true;
            })
            .map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              className={({ isActive }) =>
                cn(
                  'flex items-center px-4 py-2.5 mx-2 rounded-lg text-sm transition-colors',
                  isActive
                    ? 'bg-primary/10 text-primary font-medium'
                    : 'text-text-secondary hover:bg-background-light hover:text-text-main'
                )
              }
            >
              <item.icon className="w-5 h-5 mr-3" />
              {item.label}
            </NavLink>
          ))}
        </nav>

        {/* User */}
        <div className="border-t border-border-light p-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center min-w-0">
              <div className="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                <User className="w-4 h-4 text-primary" />
              </div>
              <div className="ml-2 min-w-0">
                <div className="text-sm font-medium text-text-main truncate">
                  {user?.name || user?.username}
                </div>
                <div className="text-xs text-text-secondary">{user?.role}</div>
              </div>
            </div>
            <button
              onClick={handleLogout}
              className="p-2 text-text-secondary hover:text-status-error transition-colors"
              title="退出登录"
            >
              <LogOut className="w-4 h-4" />
            </button>
          </div>
        </div>
      </aside>

      {/* Main */}
      <main className="flex-1 flex flex-col overflow-hidden">
        <Outlet />
      </main>
    </div>
  );
}
