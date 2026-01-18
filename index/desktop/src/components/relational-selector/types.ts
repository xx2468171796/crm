import { ReactNode } from 'react';

// 列配置
export interface ColumnConfig<T> {
  key: keyof T | string;
  label: string;
  width?: number;
  render?: (item: T) => ReactNode;
}

// 基础选择器 Props
export interface RelationalSelectorProps<T extends { id: number | string }> {
  // 弹窗控制
  open: boolean;
  onClose: () => void;
  
  // 显示配置
  title: string;
  columns: ColumnConfig<T>[];
  searchPlaceholder?: string;
  emptyText?: string;
  
  // 数据源
  fetchData: (search: string) => Promise<T[]>;
  
  // 选择模式
  mode: 'single' | 'multiple';
  value: (number | string)[];
  onChange: (value: (number | string)[], items: T[]) => void;
  
  // 其他
  loading?: boolean;
}

// 项目数据
export interface ProjectItem {
  id: number;
  project_code: string;
  project_name: string;
  customer_name: string;
  current_status: string;
}

// 用户数据
export interface UserItem {
  id: number;
  username: string;
  realname: string;
  role: string;
  avatar?: string;
  department?: string;
}

// 客户数据
export interface CustomerItem {
  id: number;
  name: string;
  group_code: string;
  phone: string;
  sales_name?: string;
}

// 项目选择器 Props
export interface ProjectSelectorProps {
  open: boolean;
  onClose: () => void;
  mode?: 'single' | 'multiple';
  value: number[];
  onChange: (value: number[], items: ProjectItem[]) => void;
}

// 用户选择器 Props
export interface UserSelectorProps {
  open: boolean;
  onClose: () => void;
  mode?: 'single' | 'multiple';
  value: number[];
  onChange: (value: number[], items: UserItem[]) => void;
  roleFilter?: string; // 角色筛选
}
