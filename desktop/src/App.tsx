import { useEffect, Component, type ReactNode, type ErrorInfo } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { Toaster } from '@/components/ui/toaster';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { usePermissionsStore } from '@/stores/permissions';
import { syncSettings, onEvent, EVENTS } from '@/lib/windowEvents';

// ---- Error Boundary ----
interface ErrorBoundaryProps {
  children: ReactNode;
}
interface ErrorBoundaryState {
  hasError: boolean;
  error: Error | null;
}

class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  constructor(props: ErrorBoundaryProps) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error: Error): ErrorBoundaryState {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('[ErrorBoundary] 应用渲染错误:', error, info.componentStack);
  }

  handleReload = () => {
    this.setState({ hasError: false, error: null });
    window.location.reload();
  };

  render() {
    if (this.state.hasError) {
      return (
        <div style={{
          display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center',
          minHeight: '100vh', padding: '2rem', fontFamily: 'system-ui, sans-serif',
          backgroundColor: '#f9fafb', color: '#111827',
        }}>
          <div style={{
            backgroundColor: '#fff', borderRadius: '12px', boxShadow: '0 4px 24px rgba(0,0,0,0.08)',
            padding: '2.5rem', maxWidth: '480px', width: '100%', textAlign: 'center',
          }}>
            <div style={{ fontSize: '3rem', marginBottom: '1rem' }}>⚠️</div>
            <h2 style={{ fontSize: '1.25rem', fontWeight: 600, marginBottom: '0.75rem' }}>
              应用出现异常
            </h2>
            <p style={{ color: '#6b7280', fontSize: '0.875rem', marginBottom: '1.5rem', lineHeight: 1.6 }}>
              {this.state.error?.message || '发生了未知错误，请尝试重新加载应用。'}
            </p>
            <button
              onClick={this.handleReload}
              style={{
                backgroundColor: '#3b82f6', color: '#fff', border: 'none', borderRadius: '8px',
                padding: '0.625rem 1.5rem', fontSize: '0.875rem', fontWeight: 500,
                cursor: 'pointer', transition: 'background-color 0.2s',
              }}
              onMouseOver={(e) => (e.currentTarget.style.backgroundColor = '#2563eb')}
              onMouseOut={(e) => (e.currentTarget.style.backgroundColor = '#3b82f6')}
            >
              重新加载
            </button>
          </div>
        </div>
      );
    }
    return this.props.children;
  }
}
import Layout from '@/components/Layout';
import LoginPage from '@/pages/LoginPage';
import DashboardPage from '@/pages/DashboardPage';
import ProjectKanbanPage from '@/pages/ProjectKanbanPage';
import TasksPage from '@/pages/TasksPage';
import ApprovalPage from '@/pages/ApprovalPage';
import FileLogsPage from '@/pages/FileLogsPage';
import FinancePage from '@/pages/FinancePage';
import SettingsPage from '@/pages/SettingsPage';
import FloatingWindow from '@/pages/FloatingWindow';
import ProjectDetailPage from '@/pages/ProjectDetailPage';
import CustomerDetailPage from '@/pages/CustomerDetailPage';
import FormListPage from '@/pages/FormListPage';
import TeamProgressPage from '@/pages/TeamProgressPage';
import TeamProjectsPage from '@/pages/TeamProjectsPage';
import TechCommissionPage from '@/pages/TechCommissionPage';
import FileSyncPage from '@/pages/FileSyncPage';
import PersonalDrivePage from '@/pages/PersonalDrivePage';

function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated);
  const token = useAuthStore((state) => state.token);
  const expireAt = useAuthStore((state) => state.expireAt);
  const logout = useAuthStore((state) => state.logout);
  const serverUrl = useSettingsStore((state) => state.serverUrl);
  const { data: permissionsData, fetchPermissions } = usePermissionsStore();

  // Token 过期检查
  const isTokenExpired = expireAt ? Date.now() / 1000 > expireAt : false;

  useEffect(() => {
    if (isAuthenticated && isTokenExpired) {
      console.warn('[App] Token 已过期，自动登出');
      logout();
    }
  }, [isAuthenticated, isTokenExpired, logout]);

  // 如果已登录但权限数据为空，则加载权限
  useEffect(() => {
    if (isAuthenticated && token && serverUrl && !permissionsData && !isTokenExpired) {
      console.log('[App] 权限数据为空，正在加载...');
      fetchPermissions(serverUrl, token);
    }
  }, [isAuthenticated, token, serverUrl, permissionsData, fetchPermissions, isTokenExpired]);

  if (!isAuthenticated || isTokenExpired) {
    return <Navigate to="/login" replace />;
  }

  return <>{children}</>;
}

function App() {
  const settings = useSettingsStore();
  
  // 监听悬浮窗的设置请求，响应当前设置
  useEffect(() => {
    let unlisten: (() => void) | null = null;
    
    const setupListener = async () => {
      unlisten = await onEvent(EVENTS.REQUEST_SETTINGS, () => {
        console.log('[App] 收到设置请求，发送当前设置:', { 
          serverUrl: settings.serverUrl, 
          rootDir: settings.rootDir 
        });
        syncSettings({ 
          serverUrl: settings.serverUrl, 
          rootDir: settings.rootDir 
        });
      });
    };
    
    setupListener();
    return () => {
      if (unlisten) unlisten();
    };
  }, [settings.serverUrl, settings.rootDir]);
  
  return (
    <ErrorBoundary>
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/floating" element={<FloatingWindow />} />
        <Route
          path="/"
          element={
            <ProtectedRoute>
              <Layout />
            </ProtectedRoute>
          }
        >
          <Route index element={<Navigate to="/dashboard" replace />} />
          <Route path="dashboard" element={<DashboardPage />} />
          <Route path="project-kanban" element={<ProjectKanbanPage />} />
          <Route path="forms" element={<FormListPage />} />
          <Route path="tasks" element={<TasksPage />} />
          <Route path="approval" element={<ApprovalPage />} />
          <Route path="file-logs" element={<FileLogsPage />} />
          <Route path="finance" element={<FinancePage />} />
          <Route path="settings" element={<SettingsPage />} />
          <Route path="project/:id" element={<ProjectDetailPage />} />
          <Route path="customer/:id" element={<CustomerDetailPage />} />
          <Route path="team-tasks" element={<TeamProgressPage />} />
          <Route path="team-projects" element={<TeamProjectsPage />} />
          <Route path="tech-commission" element={<TechCommissionPage />} />
          <Route path="file-sync" element={<FileSyncPage />} />
          <Route path="personal-drive" element={<PersonalDrivePage />} />
        </Route>
      </Routes>
      <Toaster />
    </BrowserRouter>
    </ErrorBoundary>
  );
}

export default App;
