import { useState, useEffect } from 'react';
import { Send, MessageCircle, User } from 'lucide-react';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';

interface Comment {
  id: number;
  user_id: number;
  user_name: string;
  content: string;
  created_at: string;
}

interface TaskCommentsProps {
  taskId: number;
  onCommentAdded?: () => void;
}

export default function TaskComments({ taskId, onCommentAdded }: TaskCommentsProps) {
  const { token } = useAuthStore();
  const { serverUrl } = useSettingsStore();
  const [comments, setComments] = useState<Comment[]>([]);
  const [newComment, setNewComment] = useState('');
  const [loading, setLoading] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    loadComments();
  }, [taskId, serverUrl, token]);

  const loadComments = async () => {
    if (!serverUrl || !token || !taskId) return;
    setLoading(true);
    try {
      const response = await fetch(
        `${serverUrl}/api/desktop_task_comments.php?task_id=${taskId}`,
        {
          headers: { 'Authorization': `Bearer ${token}` },
        }
      );
      const data = await response.json();
      if (data.success) {
        setComments(data.data.comments || []);
      }
    } catch (e) {
      console.error('加载评论失败:', e);
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async () => {
    if (!newComment.trim() || submitting) return;

    setSubmitting(true);
    try {
      const response = await fetch(`${serverUrl}/api/desktop_task_comments.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          task_id: taskId,
          content: newComment.trim(),
        }),
      });
      const data = await response.json();
      if (data.success) {
        setNewComment('');
        loadComments();
        onCommentAdded?.();
      }
    } catch (e) {
      console.error('发送评论失败:', e);
    } finally {
      setSubmitting(false);
    }
  };

  const handleKeyPress = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSubmit();
    }
  };

  return (
    <div className="flex flex-col h-full">
      {/* 标题 */}
      <div className="flex items-center gap-2 px-4 py-3 border-b">
        <MessageCircle className="w-4 h-4 text-gray-500" />
        <span className="text-sm font-medium text-gray-700">任务评论</span>
        <span className="text-xs text-gray-400">({comments.length})</span>
      </div>

      {/* 评论列表 */}
      <div className="flex-1 overflow-y-auto p-4 space-y-3">
        {loading ? (
          <div className="text-center text-gray-400 text-sm py-4">加载中...</div>
        ) : comments.length === 0 ? (
          <div className="text-center text-gray-400 text-sm py-4">暂无评论</div>
        ) : (
          comments.map((comment) => (
            <div key={comment.id} className="flex gap-3">
              <div className="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center flex-shrink-0">
                <User className="w-4 h-4 text-gray-500" />
              </div>
              <div className="flex-1 min-w-0">
                <div className="flex items-baseline gap-2">
                  <span className="text-sm font-medium text-gray-800">
                    {comment.user_name}
                  </span>
                  <span className="text-xs text-gray-400">
                    {comment.created_at}
                  </span>
                </div>
                <p className="text-sm text-gray-600 mt-1 whitespace-pre-wrap break-words">
                  {comment.content}
                </p>
              </div>
            </div>
          ))
        )}
      </div>

      {/* 输入框 */}
      <div className="p-3 border-t">
        <div className="flex gap-2">
          <textarea
            value={newComment}
            onChange={(e) => setNewComment(e.target.value)}
            onKeyPress={handleKeyPress}
            placeholder="输入评论..."
            rows={2}
            className="flex-1 px-3 py-2 border rounded-lg text-sm resize-none focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <button
            onClick={handleSubmit}
            disabled={!newComment.trim() || submitting}
            className={`px-4 rounded-lg transition-colors ${
              !newComment.trim() || submitting
                ? 'bg-gray-100 text-gray-400'
                : 'bg-blue-500 text-white hover:bg-blue-600'
            }`}
          >
            <Send className="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>
  );
}
