import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { FolderKanban, RefreshCw, AlertTriangle, Clock, CheckCircle } from 'lucide-react';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';

interface Project {
  id: number;
  project_code: string;
  project_name: string;
  customer_name: string;
  current_status: string;
  stage_name: string;
  stage_color: string;
  stage_deadline: string | null;
  remaining_days: number | null;
  deadline_status: 'overdue' | 'urgent' | 'normal';
  tech_names: string;
}

interface ProjectGroup {
  status: string;
  stage_name: string;
  stage_color: string;
  projects: Project[];
  count: number;
}

interface Stats {
  total: number;
  overdue: number;
  urgent: number;
}

export default function TeamProjectsPage() {
  const navigate = useNavigate();
  const { token, user } = useAuthStore();
  const { serverUrl } = useSettingsStore();
  const [groups, setGroups] = useState<ProjectGroup[]>([]);
  const [stats, setStats] = useState<Stats>({ total: 0, overdue: 0, urgent: 0 });
  const [loading, setLoading] = useState(true);

  const loadTeamProjects = async () => {
    setLoading(true);
    try {
      const response = await fetch(`${serverUrl}/api/desktop_team_projects.php`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });
      const data = await response.json();
      if (data.success) {
        setGroups(data.data.groups || []);
        setStats(data.data.stats || { total: 0, overdue: 0, urgent: 0 });
      }
    } catch (error) {
      console.error('加载团队项目失败:', error);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (serverUrl && token) {
      loadTeamProjects();
    }
  }, [serverUrl, token]);

  const getDeadlineIcon = (status: string) => {
    switch (status) {
      case 'overdue': return <AlertTriangle size={14} className="text-red-500" />;
      case 'urgent': return <Clock size={14} className="text-orange-500" />;
      default: return <CheckCircle size={14} className="text-green-500" />;
    }
  };

  const getDeadlineText = (project: Project) => {
    if (!project.stage_deadline) return '无截止日期';
    if (project.deadline_status === 'overdue') {
      return `超期 ${Math.abs(project.remaining_days || 0)} 天`;
    }
    if (project.remaining_days === 0) return '今天到期';
    return `剩余 ${project.remaining_days} 天`;
  };

  const { toast } = useToast();
  const isManagerRole = isManager(user?.role);
  
  if (!isManagerRole) {
    return (
      <div className="flex-1 flex items-center justify-center bg-gray-50">
        <div className="text-center text-gray-500">
          <FolderKanban size={48} className="mx-auto mb-4 opacity-50" />
          <p>仅主管和管理员可查看团队项目进度</p>
        </div>
      </div>
    );
  }

  return (
    <div className="flex-1 flex flex-col h-full bg-gray-50">
      {/* 头部 */}
      <div className="bg-white border-b px-6 py-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <FolderKanban size={24} className="text-blue-500" />
            <h1 className="text-xl font-semibold text-gray-800">团队项目进度</h1>
          </div>
          <div className="flex items-center gap-4">
            {/* 统计 */}
            <div className="flex items-center gap-4 text-sm">
              <span className="text-gray-500">共 {stats.total} 个项目</span>
              {stats.overdue > 0 && (
                <span className="flex items-center gap-1 text-red-500">
                  <AlertTriangle size={14} />
                  {stats.overdue} 超期
                </span>
              )}
              {stats.urgent > 0 && (
                <span className="flex items-center gap-1 text-orange-500">
                  <Clock size={14} />
                  {stats.urgent} 即将到期
                </span>
              )}
            </div>
            <button
              onClick={loadTeamProjects}
              className="p-2 text-gray-500 hover:bg-gray-100 rounded-lg"
              title="刷新"
            >
              <RefreshCw size={18} className={loading ? 'animate-spin' : ''} />
            </button>
          </div>
        </div>
      </div>

      {/* 项目分组 */}
      <div className="flex-1 overflow-y-auto p-6">
        {loading ? (
          <div className="flex items-center justify-center h-64 text-gray-400">加载中...</div>
        ) : groups.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-64 text-gray-400">
            <FolderKanban size={48} className="mb-4 opacity-50" />
            <p>暂无进行中的项目</p>
          </div>
        ) : (
          <div className="space-y-6">
            {groups.map((group) => (
              <div key={group.status} className="bg-white rounded-lg border overflow-hidden">
                {/* 分组头部 */}
                <div className="px-4 py-3 border-b bg-gray-50 flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <span
                      className="w-3 h-3 rounded-full"
                      style={{ backgroundColor: group.stage_color }}
                    />
                    <span className="font-medium text-gray-800">{group.stage_name}</span>
                    <span className="text-sm text-gray-500">({group.count})</span>
                  </div>
                </div>

                {/* 项目列表 */}
                <div className="divide-y">
                  {group.projects.map((project) => (
                    <div
                      key={project.id}
                      className="p-4 hover:bg-gray-50 cursor-pointer transition-colors"
                      onClick={() => navigate(`/project/${project.id}`)}
                    >
                      <div className="flex items-start justify-between">
                        <div className="flex-1">
                          <div className="flex items-center gap-2">
                            <span className="font-medium text-gray-800">{project.project_name}</span>
                            <span className="text-xs text-gray-400">{project.project_code}</span>
                          </div>
                          <div className="flex items-center gap-3 mt-1 text-sm text-gray-500">
                            <span>{project.customer_name}</span>
                            <span>•</span>
                            <span>{project.tech_names}</span>
                          </div>
                        </div>
                        <div className="flex items-center gap-2">
                          {project.stage_deadline && (
                            <div className={`flex items-center gap-1 text-sm ${
                              project.deadline_status === 'overdue' ? 'text-red-500' :
                              project.deadline_status === 'urgent' ? 'text-orange-500' : 'text-gray-500'
                            }`}>
                              {getDeadlineIcon(project.deadline_status)}
                              <span>{getDeadlineText(project)}</span>
                            </div>
                          )}
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
