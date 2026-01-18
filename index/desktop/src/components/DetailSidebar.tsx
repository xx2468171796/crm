import { ReactNode } from 'react';

export interface SidebarTab {
  key: string;
  label: string;
  icon?: ReactNode;
  badge?: number;
}

interface DetailSidebarProps {
  tabs: SidebarTab[];
  activeTab: string;
  onTabChange: (key: string) => void;
}

export default function DetailSidebar({ tabs, activeTab, onTabChange }: DetailSidebarProps) {
  return (
    <div className="w-48 bg-white border-r flex-shrink-0">
      <nav className="p-2 space-y-1">
        {tabs.map((tab) => (
          <button
            key={tab.key}
            onClick={() => onTabChange(tab.key)}
            className={`w-full flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-lg transition-colors ${
              activeTab === tab.key
                ? 'bg-indigo-100 text-indigo-700'
                : 'text-gray-600 hover:bg-gray-100'
            }`}
          >
            {tab.icon && <span className="w-4 h-4">{tab.icon}</span>}
            <span className="flex-1 text-left">{tab.label}</span>
            {tab.badge !== undefined && tab.badge > 0 && (
              <span className="px-2 py-0.5 text-xs bg-red-100 text-red-600 rounded-full">
                {tab.badge}
              </span>
            )}
          </button>
        ))}
      </nav>
    </div>
  );
}
