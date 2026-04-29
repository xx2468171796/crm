/**
 * 日期工具：避免 JS toISOString() 转 UTC 造成日期偏一天的 bug
 *
 * 错误示例（GMT+8 用户，本地 2026-04-01 00:00）：
 *   new Date(2026, 3, 1).toISOString()        → "2026-03-31T16:00:00.000Z"
 *   .split('T')[0]                            → "2026-03-31"  ❌
 *
 * 用本工具就能直接拿本地日期：
 *   fmtLocalDate(new Date(2026, 3, 1))         → "2026-04-01"  ✓
 */

/** YYYY-MM-DD 本地日期 */
export function fmtLocalDate(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** YYYY-MM-DDTHH:mm 本地（适合 datetime-local input） */
export function fmtLocalDatetime(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** unix 秒转本地日期 (YYYY-MM-DD) */
export function unixToLocalDate(ts: number | null | undefined): string {
  if (!ts) return '';
  return fmtLocalDate(new Date(ts * 1000));
}
