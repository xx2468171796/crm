import { useEffect } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { Toaster } from '@/components/ui/toaster';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { usePermissionsStore } from '@/stores/permissions';
import { syncSettings, onEvent, EVENTS } from '@/lib/windowEvents';
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
  const serverUrl = useSettingsStore((state) => state.serverUrl);
  const { data: permissionsData, fetchPermissions } = usePermissionsStore();

  // 如果已登录但权限数据为空，则加载权限
  useEffect(() => {
    if (isAuthenticated && token && serverUrl && !permissionsData) {
      console.log('[App] 权限数据为空，正在加载...');
      fetchPermissions(serverUrl, token);
    }
  }, [isAuthenticated, token, serverUrl, permissionsData, fetchPermissions]);

  if (!isAuthenticated) {
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
  );
}

export default App;
