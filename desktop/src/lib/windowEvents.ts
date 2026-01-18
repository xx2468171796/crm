/**
 * 窗口间事件通信工具
 * 用于主窗口和悬浮窗之间的数据同步
 */

import { emit, listen, UnlistenFn } from '@tauri-apps/api/event';

// 事件类型
export const EVENTS = {
  TASKS_UPDATED: 'tasks-updated',
  OPEN_TASK_DETAIL: 'open-task-detail',
  OPEN_PROJECT_DETAIL: 'open-project-detail',
  OPEN_FORM_DETAIL: 'open-form-detail',
  PROJECT_STAGE_CHANGED: 'project-stage-changed',
  SYNC_STATUS_CHANGED: 'sync-status-changed',
  REFRESH_DATA: 'refresh-data',
  NOTIFICATION: 'notification',
  SETTINGS_SYNC: 'settings-sync',
  REQUEST_SETTINGS: 'request-settings',
} as const;

// 事件负载类型
export interface TasksUpdatedPayload {
  tasks: Array<{
    id: number;
    title: string;
    project_name: string;
    priority: string;
    deadline: string;
    status: string;
  }>;
}

export interface OpenTaskDetailPayload {
  taskId: number;
  projectId?: number;
}

export interface SyncStatusPayload {
  status: 'idle' | 'syncing' | 'error';
  message?: string;
}

// 发送事件到所有窗口
export async function emitToAll<T>(event: string, payload: T): Promise<void> {
  try {
    await emit(event, payload);
  } catch (e) {
    console.error(`发送事件失败 [${event}]:`, e);
  }
}

// 监听事件
export function onEvent<T>(
  event: string,
  handler: (payload: T) => void
): Promise<UnlistenFn> {
  return listen<T>(event, (e) => handler(e.payload));
}

// 通知任务更新
export async function notifyTasksUpdated(tasks: TasksUpdatedPayload['tasks']): Promise<void> {
  await emitToAll(EVENTS.TASKS_UPDATED, { tasks });
}

// 请求打开任务详情
export async function requestOpenTaskDetail(taskId: number, projectId?: number): Promise<void> {
  await emitToAll(EVENTS.OPEN_TASK_DETAIL, { taskId, projectId });
}

// 通知同步状态变化
export async function notifySyncStatus(status: SyncStatusPayload['status'], message?: string): Promise<void> {
  await emitToAll(EVENTS.SYNC_STATUS_CHANGED, { status, message });
}

// 请求刷新数据
export async function requestRefreshData(): Promise<void> {
  await emitToAll(EVENTS.REFRESH_DATA, {});
}

// 请求打开项目详情
export async function requestOpenProjectDetail(projectId: number): Promise<void> {
  await emitToAll(EVENTS.OPEN_PROJECT_DETAIL, { projectId });
}

// 通知项目阶段变化
export async function notifyProjectStageChanged(projectId: number, stage: string): Promise<void> {
  await emitToAll(EVENTS.PROJECT_STAGE_CHANGED, { projectId, stage });
}

// 请求打开表单详情
export async function requestOpenFormDetail(projectId: number, formId?: number): Promise<void> {
  await emitToAll(EVENTS.OPEN_FORM_DETAIL, { projectId, formId });
}

// 发送通知
export async function sendNotification(type: 'form' | 'task' | 'evaluation', title: string, message?: string): Promise<void> {
  await emitToAll(EVENTS.NOTIFICATION, { type, title, message });
}

// 设置同步负载类型
export interface SettingsSyncPayload {
  serverUrl: string;
  rootDir: string;
  token?: string;
}

// 同步设置到所有窗口
export async function syncSettings(settings: SettingsSyncPayload): Promise<void> {
  await emitToAll(EVENTS.SETTINGS_SYNC, settings);
}

// 请求主窗口发送设置
export async function requestSettings(): Promise<void> {
  await emitToAll(EVENTS.REQUEST_SETTINGS, {});
}
