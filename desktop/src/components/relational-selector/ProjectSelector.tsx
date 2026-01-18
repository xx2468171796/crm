import { useCallback } from 'react';
import { useSettingsStore } from '@/stores/settings';
import { useAuthStore } from '@/stores/auth';
import RelationalSelector from './RelationalSelector';
import { ProjectItem, ProjectSelectorProps, ColumnConfig } from './types';

const PROJECT_COLUMNS: ColumnConfig<ProjectItem>[] = [
  { key: 'project_code', label: '项目编号', width: 140 },
  { key: 'project_name', label: '项目名称', width: 160 },
  { key: 'customer_name', label: '客户', width: 140 },
  {
    key: 'current_status',
    label: '状态',
    width: 100,
    render: (item) => (
      <span className="px-2 py-0.5 text-xs rounded bg-blue-100 text-blue-700">
        {item.current_status}
      </span>
    ),
  },
];

export default function ProjectSelector({
  open,
  onClose,
  mode = 'single',
  value,
  onChange,
}: ProjectSelectorProps) {
  const { serverUrl } = useSettingsStore();
  const { token } = useAuthStore();

  const fetchProjects = useCallback(async (search: string): Promise<ProjectItem[]> => {
    if (!serverUrl || !token) return [];
    
    const params = new URLSearchParams({
      type: 'project',
      q: search,
      limit: '50',
    });
    
    const response = await fetch(`${serverUrl}/api/desktop_search.php?${params}`, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
    });
    
    const data = await response.json();
    return data.success ? data.data : [];
  }, [serverUrl, token]);

  return (
    <RelationalSelector<ProjectItem>
      open={open}
      onClose={onClose}
      title="选择关联项目"
      columns={PROJECT_COLUMNS}
      searchPlaceholder="搜索项目编号、名称、客户..."
      emptyText="暂无项目"
      fetchData={fetchProjects}
      mode={mode}
      value={value}
      onChange={(ids, items) => onChange(ids as number[], items)}
    />
  );
}
