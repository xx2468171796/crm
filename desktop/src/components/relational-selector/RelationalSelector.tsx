import { useState, useEffect, useCallback } from 'react';
import { X, Search, Check, Loader2 } from 'lucide-react';
import { RelationalSelectorProps } from './types';

export default function RelationalSelector<T extends { id: number | string }>({
  open,
  onClose,
  title,
  columns,
  searchPlaceholder = '搜索...',
  emptyText = '暂无数据',
  fetchData,
  mode,
  value,
  onChange,
}: RelationalSelectorProps<T>) {
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(false);
  const [data, setData] = useState<T[]>([]);
  const [selected, setSelected] = useState<(number | string)[]>(value);

  // 防抖搜索
  useEffect(() => {
    if (!open) return;
    
    const timer = setTimeout(async () => {
      setLoading(true);
      try {
        const result = await fetchData(search);
        setData(result);
      } catch (error) {
        console.error('搜索失败:', error);
        setData([]);
      } finally {
        setLoading(false);
      }
    }, 300);

    return () => clearTimeout(timer);
  }, [search, open, fetchData]);

  // 同步外部值
  useEffect(() => {
    setSelected(value);
  }, [value]);

  // 重置状态
  useEffect(() => {
    if (open) {
      setSearch('');
      setSelected(value);
    }
  }, [open, value]);

  // 切换选中
  const toggleSelect = useCallback((id: number | string) => {
    if (mode === 'single') {
      setSelected([id]);
    } else {
      setSelected((prev) =>
        prev.includes(id) ? prev.filter((v) => v !== id) : [...prev, id]
      );
    }
  }, [mode]);

  // 确认选择
  const handleConfirm = () => {
    const selectedItems = data.filter((item) => selected.includes(item.id));
    onChange(selected, selectedItems);
    onClose();
  };

  // 取消
  const handleCancel = () => {
    setSelected(value);
    onClose();
  };

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      {/* 遮罩 */}
      <div
        className="absolute inset-0 bg-black/50"
        onClick={handleCancel}
      />
      
      {/* 弹窗 */}
      <div className="relative bg-white rounded-xl shadow-2xl w-[700px] max-h-[80vh] flex flex-col">
        {/* 头部 */}
        <div className="flex items-center justify-between px-6 py-4 border-b">
          <h2 className="text-lg font-semibold text-gray-800">{title}</h2>
          <button
            onClick={handleCancel}
            className="p-1 text-gray-400 hover:text-gray-600 rounded transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* 搜索框 */}
        <div className="px-6 py-3 border-b">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={searchPlaceholder}
              className="w-full pl-10 pr-4 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              autoFocus
            />
          </div>
        </div>

        {/* 表格 */}
        <div className="flex-1 overflow-auto">
          {loading ? (
            <div className="flex items-center justify-center h-48">
              <Loader2 className="w-6 h-6 text-blue-500 animate-spin" />
            </div>
          ) : data.length === 0 ? (
            <div className="flex items-center justify-center h-48 text-gray-400">
              {emptyText}
            </div>
          ) : (
            <table className="w-full">
              <thead className="bg-gray-50 sticky top-0">
                <tr>
                  <th className="w-10 px-4 py-3"></th>
                  {columns.map((col) => (
                    <th
                      key={String(col.key)}
                      className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"
                      style={{ width: col.width }}
                    >
                      {col.label}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y">
                {data.map((item) => {
                  const isSelected = selected.includes(item.id);
                  return (
                    <tr
                      key={item.id}
                      onClick={() => toggleSelect(item.id)}
                      className={`cursor-pointer transition-colors ${
                        isSelected
                          ? 'bg-blue-50 hover:bg-blue-100'
                          : 'hover:bg-gray-50'
                      }`}
                    >
                      <td className="px-4 py-3">
                        <div
                          className={`w-5 h-5 rounded border-2 flex items-center justify-center ${
                            isSelected
                              ? 'bg-blue-500 border-blue-500'
                              : 'border-gray-300'
                          }`}
                        >
                          {isSelected && <Check className="w-3 h-3 text-white" />}
                        </div>
                      </td>
                      {columns.map((col) => (
                        <td
                          key={String(col.key)}
                          className="px-4 py-3 text-sm text-gray-700"
                        >
                          {col.render
                            ? col.render(item)
                            : String((item as any)[col.key] ?? '')}
                        </td>
                      ))}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </div>

        {/* 底部 */}
        <div className="flex items-center justify-between px-6 py-4 border-t bg-gray-50">
          <span className="text-sm text-gray-500">
            已选择: <b className="text-gray-800">{selected.length}</b> 项
          </span>
          <div className="flex gap-3">
            <button
              onClick={handleCancel}
              className="px-4 py-2 text-sm text-gray-600 bg-white border rounded-lg hover:bg-gray-50 transition-colors"
            >
              取消
            </button>
            <button
              onClick={handleConfirm}
              className="px-4 py-2 text-sm text-white bg-blue-500 rounded-lg hover:bg-blue-600 transition-colors"
            >
              确定
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
