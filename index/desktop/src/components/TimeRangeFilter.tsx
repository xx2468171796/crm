import { useState, useEffect } from 'react';
import { Calendar } from 'lucide-react';

export type TimeRange = 'all' | 'month' | 'last_month' | 'custom';

interface TimeRangeFilterProps {
  value: TimeRange;
  onChange: (range: TimeRange, startDate: string, endDate: string) => void;
  className?: string;
  compact?: boolean; // 紧凑模式，用于悬浮窗
}

export default function TimeRangeFilter({ value, onChange, className = '', compact = false }: TimeRangeFilterProps) {
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  
  // 计算预设日期范围
  const getDateRange = (range: TimeRange): { start: string; end: string } => {
    const now = new Date();
    switch (range) {
      case 'month':
        return {
          start: new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0],
          end: new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().split('T')[0],
        };
      case 'last_month':
        return {
          start: new Date(now.getFullYear(), now.getMonth() - 1, 1).toISOString().split('T')[0],
          end: new Date(now.getFullYear(), now.getMonth(), 0).toISOString().split('T')[0],
        };
      default:
        return { start: '', end: '' };
    }
  };
  
  const handleRangeChange = (range: TimeRange) => {
    if (range === 'custom') {
      onChange(range, startDate, endDate);
    } else {
      const { start, end } = getDateRange(range);
      setStartDate(start);
      setEndDate(end);
      onChange(range, start, end);
    }
  };
  
  const handleCustomDateChange = (start: string, end: string) => {
    setStartDate(start);
    setEndDate(end);
    if (value === 'custom') {
      onChange('custom', start, end);
    }
  };
  
  // 初始化日期
  useEffect(() => {
    if (value !== 'custom' && value !== 'all') {
      const { start, end } = getDateRange(value);
      setStartDate(start);
      setEndDate(end);
    }
  }, []);
  
  const options = [
    { key: 'all', label: '全部' },
    { key: 'month', label: '本月' },
    { key: 'last_month', label: '上月' },
    { key: 'custom', label: '自定义' },
  ];
  
  if (compact) {
    return (
      <div className={`flex items-center gap-2 ${className}`}>
        <Calendar className="w-3.5 h-3.5 text-gray-400" />
        <select
          value={value}
          onChange={(e) => handleRangeChange(e.target.value as TimeRange)}
          className="text-xs bg-transparent border-none focus:outline-none text-gray-600 cursor-pointer"
        >
          {options.map(opt => (
            <option key={opt.key} value={opt.key}>{opt.label}</option>
          ))}
        </select>
        {value === 'custom' && (
          <div className="flex items-center gap-1">
            <input
              type="date"
              value={startDate}
              onChange={(e) => handleCustomDateChange(e.target.value, endDate)}
              className="text-xs px-1 py-0.5 border rounded w-24"
            />
            <span className="text-gray-400">~</span>
            <input
              type="date"
              value={endDate}
              onChange={(e) => handleCustomDateChange(startDate, e.target.value)}
              className="text-xs px-1 py-0.5 border rounded w-24"
            />
          </div>
        )}
      </div>
    );
  }
  
  return (
    <div className={`flex items-center gap-4 flex-wrap ${className}`}>
      <span className="text-sm text-gray-600 font-medium">时间范围:</span>
      <div className="flex gap-2">
        {options.map(opt => (
          <button
            key={opt.key}
            onClick={() => handleRangeChange(opt.key as TimeRange)}
            className={`px-3 py-1.5 text-sm rounded-lg transition-colors ${
              value === opt.key
                ? 'bg-indigo-600 text-white'
                : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
            }`}
          >
            {opt.label}
          </button>
        ))}
      </div>
      {value === 'custom' && (
        <div className="flex items-center gap-2">
          <input
            type="date"
            value={startDate}
            onChange={(e) => handleCustomDateChange(e.target.value, endDate)}
            className="px-2 py-1 text-sm border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
          <span className="text-gray-400">~</span>
          <input
            type="date"
            value={endDate}
            onChange={(e) => handleCustomDateChange(startDate, e.target.value)}
            className="px-2 py-1 text-sm border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>
      )}
      {value !== 'all' && value !== 'custom' && startDate && endDate && (
        <span className="text-sm text-gray-400">
          {startDate} ~ {endDate}
        </span>
      )}
    </div>
  );
}
