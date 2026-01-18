import { useState, useEffect } from 'react';
import { Calendar, Clock, CheckCircle, AlertCircle, Plus, Edit2, Trash2, MessageSquare, X } from 'lucide-react';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import ConfirmDialog from '@/components/ConfirmDialog';

interface DailyTask {
  id: number;
  title: string;
  description: string | null;
  task_date: string;
  status: 'pending' | 'in_progress' | 'completed' | 'cancelled';
  progress: number;
  priority: 'low' | 'medium' | 'high' | 'urgent';
  project_name: string | null;
  customer_name: string | null;
  comment_count: number;
}

interface TaskComment {
  id: number;
  content: string;
  user_name: string;
  created_at: string;
}

export default function TaskPage() {
  const { token } = useAuthStore();
  const { serverUrl } = useSettingsStore();
  const [tasks, setTasks] = useState<DailyTask[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedDate, setSelectedDate] = useState(new Date().toISOString().split('T')[0]);
  const [showAddModal, setShowAddModal] = useState(false);
  const [showEditModal, setShowEditModal] = useState(false);
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [selectedTask, setSelectedTask] = useState<DailyTask | null>(null);
  const [comments, setComments] = useState<TaskComment[]>([]);
  const [newComment, setNewComment] = useState('');
  const [newTask, setNewTask] = useState({ title: '', description: '', priority: 'medium' });
  const [editTask, setEditTask] = useState({ id: 0, title: '', description: '', priority: 'medium', progress: 0 });
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
  const [deleteTaskId, setDeleteTaskId] = useState<number | null>(null);

  const loadTasks = async () => {
    setLoading(true);
    try {
      const response = await fetch(`${serverUrl}/api/desktop_daily_tasks.php?date=${selectedDate}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });
      const data = await response.json();
      if (data.success) {
        setTasks(data.data.items || []);
      }
    } catch (error) {
      console.error('加载任务失败:', error);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (serverUrl && token) {
      loadTasks();
    }
  }, [selectedDate, serverUrl, token]);

  const handleAddTask = async () => {
    if (!newTask.title.trim()) return;
    
    try {
      const response = await fetch(`${serverUrl}/api/desktop_daily_tasks.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          ...newTask,
          task_date: selectedDate,
        }),
      });
      const data = await response.json();
      if (data.success) {
        setShowAddModal(false);
        setNewTask({ title: '', description: '', priority: 'medium' });
        loadTasks();
      }
    } catch (error) {
      console.error('创建任务失败:', error);
    }
  };

  const handleUpdateStatus = async (taskId: number, status: string) => {
    try {
      await fetch(`${serverUrl}/api/desktop_daily_tasks.php?id=${taskId}`, {
        method: 'PUT',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ status }),
      });
      loadTasks();
    } catch (error) {
      console.error('更新状态失败:', error);
    }
  };

  const handleEditTask = async () => {
    if (!editTask.title.trim()) return;
    try {
      await fetch(`${serverUrl}/api/desktop_daily_tasks.php?id=${editTask.id}`, {
        method: 'PUT',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          title: editTask.title,
          description: editTask.description,
          priority: editTask.priority,
          progress: editTask.progress,
        }),
      });
      setShowEditModal(false);
      loadTasks();
    } catch (error) {
      console.error('编辑任务失败:', error);
    }
  };

  // 请求删除任务（打开确认弹窗）
  const requestDeleteTask = (taskId: number) => {
    setDeleteTaskId(taskId);
    setShowDeleteConfirm(true);
  };

  // 确认删除任务
  const handleDeleteTask = async () => {
    if (!deleteTaskId) return;
    try {
      await fetch(`${serverUrl}/api/desktop_daily_tasks.php?id=${deleteTaskId}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${token}` },
      });
      loadTasks();
    } catch (error) {
      console.error('删除任务失败:', error);
    } finally {
      setShowDeleteConfirm(false);
      setDeleteTaskId(null);
    }
  };

  const openEditModal = (task: DailyTask) => {
    setEditTask({
      id: task.id,
      title: task.title,
      description: task.description || '',
      priority: task.priority,
      progress: task.progress,
    });
    setShowEditModal(true);
  };

  const openDetailModal = async (task: DailyTask) => {
    setSelectedTask(task);
    setShowDetailModal(true);
    // 加载评论
    try {
      const response = await fetch(`${serverUrl}/api/desktop_task_comments.php?task_id=${task.id}`, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const data = await response.json();
      if (data.success) {
        setComments(data.data.items || []);
      }
    } catch (error) {
      console.error('加载评论失败:', error);
    }
  };

  const handleAddComment = async () => {
    if (!newComment.trim() || !selectedTask) return;
    try {
      await fetch(`${serverUrl}/api/desktop_task_comments.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          task_id: selectedTask.id,
          content: newComment,
        }),
      });
      setNewComment('');
      // 重新加载评论
      const response = await fetch(`${serverUrl}/api/desktop_task_comments.php?task_id=${selectedTask.id}`, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const data = await response.json();
      if (data.success) {
        setComments(data.data.items || []);
      }
      loadTasks(); // 更新评论数
    } catch (error) {
      console.error('添加评论失败:', error);
    }
  };

  const getPriorityColor = (priority: string) => {
    switch (priority) {
      case 'urgent': return 'bg-red-100 text-red-800 border-red-200';
      case 'high': return 'bg-orange-100 text-orange-800 border-orange-200';
      case 'medium': return 'bg-blue-100 text-blue-800 border-blue-200';
      default: return 'bg-gray-100 text-gray-800 border-gray-200';
    }
  };

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'completed': return <CheckCircle className="text-green-500" size={18} />;
      case 'in_progress': return <Clock className="text-blue-500" size={18} />;
      default: return <AlertCircle className="text-gray-400" size={18} />;
    }
  };

  return (
    <div className="flex-1 flex flex-col h-full bg-gray-50">
      {/* 头部 */}
      <div className="bg-white border-b px-6 py-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <h1 className="text-xl font-semibold text-gray-800">每日任务</h1>
            <input
              type="date"
              value={selectedDate}
              onChange={(e) => setSelectedDate(e.target.value)}
              className="px-3 py-1.5 border rounded-lg text-sm"
            />
          </div>
          <button
            onClick={() => setShowAddModal(true)}
            className="flex items-center gap-2 px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm transition-colors"
          >
            <Plus size={16} />
            添加任务
          </button>
        </div>
      </div>

      {/* 任务列表 */}
      <div className="flex-1 overflow-y-auto p-6">
        {loading ? (
          <div className="flex items-center justify-center h-64 text-gray-400">
            加载中...
          </div>
        ) : tasks.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-64 text-gray-400">
            <Calendar size={48} className="mb-4 opacity-50" />
            <p>今日暂无任务</p>
            <button
              onClick={() => setShowAddModal(true)}
              className="mt-4 text-blue-500 hover:text-blue-600"
            >
              添加第一个任务
            </button>
          </div>
        ) : (
          <div className="space-y-3">
            {tasks.map((task) => (
              <div
                key={task.id}
                className="bg-white rounded-lg border p-4 hover:shadow-md transition-shadow"
              >
                <div className="flex items-start gap-3">
                  <button
                    onClick={() => handleUpdateStatus(
                      task.id,
                      task.status === 'completed' ? 'pending' : 'completed'
                    )}
                    className="mt-0.5"
                  >
                    {getStatusIcon(task.status)}
                  </button>
                  <div className="flex-1 min-w-0 cursor-pointer" onClick={() => openDetailModal(task)}>
                    <div className="flex items-center gap-2">
                      <h3 className={`font-medium ${task.status === 'completed' ? 'line-through text-gray-400' : 'text-gray-800'}`}>
                        {task.title}
                      </h3>
                      <span className={`px-2 py-0.5 text-xs rounded border ${getPriorityColor(task.priority)}`}>
                        {task.priority === 'urgent' ? '紧急' : 
                         task.priority === 'high' ? '高' : 
                         task.priority === 'medium' ? '中' : '低'}
                      </span>
                    </div>
                    {task.description && (
                      <p className="text-sm text-gray-500 mt-1 line-clamp-2">{task.description}</p>
                    )}
                    <div className="flex items-center gap-4 mt-2 text-xs text-gray-400">
                      {task.project_name && (
                        <span>项目: {task.project_name}</span>
                      )}
                      {task.customer_name && (
                        <span>客户: {task.customer_name}</span>
                      )}
                      {task.comment_count > 0 && (
                        <span className="flex items-center gap-1">
                          <MessageSquare size={12} />
                          {task.comment_count}
                        </span>
                      )}
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <div className="text-right mr-2">
                      <div className="text-sm text-gray-500">{task.progress}%</div>
                      <div className="w-20 h-1.5 bg-gray-200 rounded-full mt-1">
                        <div
                          className="h-full bg-blue-500 rounded-full transition-all"
                          style={{ width: `${task.progress}%` }}
                        />
                      </div>
                    </div>
                    <button
                      onClick={(e) => { e.stopPropagation(); openEditModal(task); }}
                      className="p-1.5 text-gray-400 hover:text-blue-500 hover:bg-blue-50 rounded"
                    >
                      <Edit2 size={16} />
                    </button>
                    <button
                      onClick={(e) => { e.stopPropagation(); requestDeleteTask(task.id); }}
                      className="p-1.5 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded"
                    >
                      <Trash2 size={16} />
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* 添加任务弹窗 */}
      {showAddModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl w-[480px] p-6">
            <h2 className="text-lg font-semibold mb-4">添加任务</h2>
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  任务标题 *
                </label>
                <input
                  type="text"
                  value={newTask.title}
                  onChange={(e) => setNewTask({ ...newTask, title: e.target.value })}
                  placeholder="输入任务标题"
                  className="w-full px-3 py-2 border rounded-lg"
                  autoFocus
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  描述
                </label>
                <textarea
                  value={newTask.description}
                  onChange={(e) => setNewTask({ ...newTask, description: e.target.value })}
                  placeholder="任务描述（可选）"
                  rows={3}
                  className="w-full px-3 py-2 border rounded-lg resize-none"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  优先级
                </label>
                <select
                  value={newTask.priority}
                  onChange={(e) => setNewTask({ ...newTask, priority: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg"
                >
                  <option value="low">低</option>
                  <option value="medium">中</option>
                  <option value="high">高</option>
                  <option value="urgent">紧急</option>
                </select>
              </div>
            </div>
            <div className="flex justify-end gap-3 mt-6">
              <button
                onClick={() => setShowAddModal(false)}
                className="px-4 py-2 text-gray-600 hover:text-gray-800"
              >
                取消
              </button>
              <button
                onClick={handleAddTask}
                disabled={!newTask.title.trim()}
                className="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg disabled:opacity-50"
              >
                创建
              </button>
            </div>
          </div>
        </div>
      )}

      {/* 编辑任务弹窗 */}
      {showEditModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl w-[480px] p-6">
            <h2 className="text-lg font-semibold mb-4">编辑任务</h2>
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">任务标题 *</label>
                <input
                  type="text"
                  value={editTask.title}
                  onChange={(e) => setEditTask({ ...editTask, title: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">描述</label>
                <textarea
                  value={editTask.description}
                  onChange={(e) => setEditTask({ ...editTask, description: e.target.value })}
                  rows={3}
                  className="w-full px-3 py-2 border rounded-lg resize-none"
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">优先级</label>
                  <select
                    value={editTask.priority}
                    onChange={(e) => setEditTask({ ...editTask, priority: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                  >
                    <option value="low">低</option>
                    <option value="medium">中</option>
                    <option value="high">高</option>
                    <option value="urgent">紧急</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">进度 ({editTask.progress}%)</label>
                  <input
                    type="range"
                    min="0"
                    max="100"
                    value={editTask.progress}
                    onChange={(e) => setEditTask({ ...editTask, progress: parseInt(e.target.value) })}
                    className="w-full"
                  />
                </div>
              </div>
            </div>
            <div className="flex justify-end gap-3 mt-6">
              <button onClick={() => setShowEditModal(false)} className="px-4 py-2 text-gray-600 hover:text-gray-800">取消</button>
              <button onClick={handleEditTask} disabled={!editTask.title.trim()} className="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg disabled:opacity-50">保存</button>
            </div>
          </div>
        </div>
      )}

      {/* 任务详情弹窗（含评论） */}
      {showDetailModal && selectedTask && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl w-[600px] max-h-[80vh] flex flex-col">
            <div className="flex items-center justify-between p-4 border-b">
              <h2 className="text-lg font-semibold">{selectedTask.title}</h2>
              <button onClick={() => setShowDetailModal(false)} className="p-1 hover:bg-gray-100 rounded">
                <X size={20} />
              </button>
            </div>
            <div className="flex-1 overflow-y-auto p-4">
              {selectedTask.description && (
                <p className="text-gray-600 mb-4">{selectedTask.description}</p>
              )}
              <div className="flex gap-4 text-sm text-gray-500 mb-6">
                <span className={`px-2 py-1 rounded ${getPriorityColor(selectedTask.priority)}`}>
                  {selectedTask.priority === 'urgent' ? '紧急' : selectedTask.priority === 'high' ? '高' : selectedTask.priority === 'medium' ? '中' : '低'}
                </span>
                <span>进度: {selectedTask.progress}%</span>
                {selectedTask.project_name && <span>项目: {selectedTask.project_name}</span>}
              </div>
              
              {/* 评论区 */}
              <div className="border-t pt-4">
                <h3 className="font-medium mb-3 flex items-center gap-2">
                  <MessageSquare size={18} />
                  评论 ({comments.length})
                </h3>
                <div className="space-y-3 mb-4">
                  {comments.length === 0 ? (
                    <p className="text-gray-400 text-sm">暂无评论</p>
                  ) : (
                    comments.map((comment) => (
                      <div key={comment.id} className="bg-gray-50 rounded-lg p-3">
                        <div className="flex justify-between text-xs text-gray-500 mb-1">
                          <span className="font-medium">{comment.user_name}</span>
                          <span>{comment.created_at}</span>
                        </div>
                        <p className="text-sm">{comment.content}</p>
                      </div>
                    ))
                  )}
                </div>
                <div className="flex gap-2">
                  <input
                    type="text"
                    value={newComment}
                    onChange={(e) => setNewComment(e.target.value)}
                    placeholder="添加评论..."
                    className="flex-1 px-3 py-2 border rounded-lg text-sm"
                    onKeyPress={(e) => e.key === 'Enter' && handleAddComment()}
                  />
                  <button
                    onClick={handleAddComment}
                    disabled={!newComment.trim()}
                    className="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm disabled:opacity-50"
                  >
                    发送
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
      
      {/* 删除确认弹窗 */}
      <ConfirmDialog
        open={showDeleteConfirm}
        onClose={() => {
          setShowDeleteConfirm(false);
          setDeleteTaskId(null);
        }}
        onConfirm={handleDeleteTask}
        title="确认删除"
        message="确定要删除此任务吗？此操作不可恢复。"
        type="warning"
        confirmText="删除"
        cancelText="取消"
      />
    </div>
  );
}
