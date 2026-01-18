/**
 * S3 加速节点 URL 替换工具
 * 
 * 将 presigned URL 的域名替换为加速节点地址
 * 原始: http://192.168.110.246:9000/bucket/file?signature=xxx
 * 替换后: https://proxy.example.com/bucket/file?signature=xxx
 */

/**
 * 替换 URL 的域名部分
 * @param originalUrl 原始 presigned URL
 * @param accelerationUrl 加速节点 URL (如 https://proxy.example.com)
 * @returns 替换后的 URL，如果加速节点为空则返回原始 URL
 */
export function replaceUrlEndpoint(originalUrl: string, accelerationUrl: string): string {
  if (!accelerationUrl || !originalUrl) {
    return originalUrl;
  }

  try {
    const acceleration = new URL(accelerationUrl);

    // 替换协议、主机、端口，保留路径和查询参数
    const newUrl = new URL(originalUrl);
    newUrl.protocol = acceleration.protocol;
    newUrl.host = acceleration.host;
    // 如果加速节点有端口，使用加速节点的端口；否则使用默认端口
    if (acceleration.port) {
      newUrl.port = acceleration.port;
    } else {
      newUrl.port = '';
    }

    return newUrl.toString();
  } catch (error) {
    console.error('[URL_REPLACER] URL 替换失败:', error, { originalUrl, accelerationUrl });
    return originalUrl;
  }
}

/**
 * 从 settings store 获取加速节点 URL 并替换
 * @param originalUrl 原始 presigned URL
 * @param accelerationNodeUrl 加速节点 URL（从 settings store 获取）
 * @returns 替换后的 URL
 */
export function applyAcceleration(originalUrl: string, accelerationNodeUrl: string | null | undefined): string {
  if (!accelerationNodeUrl) {
    return originalUrl;
  }
  return replaceUrlEndpoint(originalUrl, accelerationNodeUrl);
}
