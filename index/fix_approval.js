const fs = require('fs');
const file = 'd:/aiDDDDDDM/WWW/crmchonggou/index/desktop/src/pages/ApprovalPage.tsx';
let content = fs.readFileSync(file, 'utf8');

const oldCode = `            {/* 统计 */}
            <div className="flex gap-4 text-sm">
              <span className="text-yellow-600">待审批 <b>{stats.pending}</b></span>
              <span className="text-green-600">已通过 <b>{stats.approved}</b></span>
              <span className="text-red-600">已驳回 <b>{stats.rejected}</b></span>
            </div>`;

const newCode = `            {/* 状态切换按钮 */}
            <div className="flex bg-gray-100 rounded-lg p-0.5">
              <button onClick={() => setFilters(prev => ({ ...prev, status: 'pending' }))} className={\`px-3 py-1 text-sm rounded \${filters.status === 'pending' ? 'bg-yellow-500 text-white' : 'text-yellow-600 hover:bg-yellow-50'}\`}>待审批 {stats.pending}</button>
              <button onClick={() => setFilters(prev => ({ ...prev, status: 'approved' }))} className={\`px-3 py-1 text-sm rounded \${filters.status === 'approved' ? 'bg-green-500 text-white' : 'text-green-600 hover:bg-green-50'}\`}>已通过 {stats.approved}</button>
              <button onClick={() => setFilters(prev => ({ ...prev, status: 'rejected' }))} className={\`px-3 py-1 text-sm rounded \${filters.status === 'rejected' ? 'bg-red-500 text-white' : 'text-red-600 hover:bg-red-50'}\`}>已驳回 {stats.rejected}</button>
            </div>`;

if (content.includes('统计')) {
  content = content.replace(oldCode, newCode);
  fs.writeFileSync(file, content, 'utf8');
  console.log('Modified successfully');
} else {
  console.log('Pattern not found');
}
