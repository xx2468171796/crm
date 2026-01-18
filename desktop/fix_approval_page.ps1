# 修复ApprovalPage.tsx的编译错误
$file = "d:\aiDDDDDDM\WWW\crmchonggou\index\desktop\src\pages\ApprovalPage.tsx"
$content = Get-Content $file -Raw -Encoding UTF8

# 删除未使用的StatusFilter类型
$content = $content -replace "type StatusFilter = 'pending' \| 'approved' \| 'rejected' \| 'all';`r?`n", ""

# 删除TimeRangeFilter导入
$content = $content -replace "import TimeRangeFilter, \{ TimeRange \} from '@/components/TimeRangeFilter';`r?`n", ""

# 删除statusFilter状态变量
$content = $content -replace "  const \[statusFilter, setStatusFilter\] = useState<StatusFilter>\('pending'\);`r?`n", ""

# 删除timeRange状态变量
$content = $content -replace "  // 时间筛选`r?`n  const \[timeRange, setTimeRange\] = useState<TimeRange>\('all'\);`r?`n  const \[startDate, setStartDate\] = useState\(''\);`r?`n  const \[endDate, setEndDate\] = useState\(''\);`r?`n", ""

# 修复setStatusFilter调用
$content = $content -replace "setActiveTab\('pending'\); setStatusFilter\('pending'\);", "setActiveTab('pending'); setFilters(prev => ({ ...prev, status: 'pending' }));"

# 删除TimeRangeFilter组件使用
$content = $content -replace "            <!-- 时间筛选 -->`r?`n            <TimeRangeFilter[^>]+>[^<]*</TimeRangeFilter>`r?`n", ""

Set-Content $file $content -Encoding UTF8 -NoNewline

Write-Host "ApprovalPage.tsx已修复"
