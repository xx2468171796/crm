import { useCallback } from 'react';
import { User } from 'lucide-react';
import { useSettingsStore } from '@/stores/settings';
import { useAuthStore } from '@/stores/auth';
import RelationalSelector from './RelationalSelector';
import { UserItem, UserSelectorProps, ColumnConfig } from './types';

const ROLE_LABELS: Record<string, string> = {
  admin: '管理员',
  super_admin: '超级管理员',
  tech: '技术',
  tech_manager: '技术主管',
  sales: '销售',
  manager: '经理',
  finance: '财务',
};

const USER_COLUMNS: ColumnConfig<UserItem>[] = [
  {
    key: 'avatar',
    label: '',
    width: 50,
    render: (item) => (
      <div className="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center overflow-hidden">
        {item.avatar ? (
          <img src={item.avatar} alt="" className="w-full h-full object-cover" />
        ) : (
          <User className="w-4 h-4 text-gray-400" />
        )}
      </div>
    ),
  },
  { key: 'realname', label: '姓名', width: 120 },
  { key: 'username', label: '用户名', width: 120 },
  {
    key: 'role',
    label: '角色',
    width: 100,
    render: (item) => (
      <span className="px-2 py-0.5 text-xs rounded bg-purple-100 text-purple-700">
        {ROLE_LABELS[item.role] || item.role}
      </span>
    ),
  },
];

export default function UserSelector({
  open,
  onClose,
  mode = 'single',
  value,
  onChange,
  roleFilter,
}: UserSelectorProps) {
  const { serverUrl } = useSettingsStore();
  const { token } = useAuthStore();

  const fetchUsers = useCallback(async (search: string): Promise<UserItem[]> => {
    if (!serverUrl || !token) return [];
    
    const params = new URLSearchParams({
      type: 'user',
      q: search,
      limit: '50',
    });
    
    if (roleFilter) {
      params.append('role', roleFilter);
    }
    
    const response = await fetch(`${serverUrl}/api/desktop_search.php?${params}`, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
    });
    
    const data = await response.json();
    return data.success ? data.data : [];
  }, [serverUrl, token, roleFilter]);

  return (
    <RelationalSelector<UserItem>
      open={open}
      onClose={onClose}
      title={roleFilter === 'tech' ? '选择技术人员' : '选择人员'}
      columns={USER_COLUMNS}
      searchPlaceholder="搜索姓名、用户名..."
      emptyText="暂无人员"
      fetchData={fetchUsers}
      mode={mode}
      value={value}
      onChange={(ids, items) => onChange(ids as number[], items)}
    />
  );
}
