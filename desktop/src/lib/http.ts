import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';

export interface ApiResponse<T = unknown> {
  success: boolean;
  data?: T;
  error?: {
    code: string;
    message: string;
  };
}

class HttpClient {
  private readonly maxConcurrentRequests = 6;
  private activeRequests = 0;
  private waitQueue: Array<() => void> = [];
  private inflightGet = new Map<string, Promise<ApiResponse<unknown>>>();

  private async acquireSlot(): Promise<void> {
    if (this.activeRequests < this.maxConcurrentRequests) {
      this.activeRequests++;
      return;
    }
    await new Promise<void>((resolve) => {
      this.waitQueue.push(resolve);
    });
    this.activeRequests++;
  }

  private releaseSlot(): void {
    this.activeRequests = Math.max(0, this.activeRequests - 1);
    const next = this.waitQueue.shift();
    if (next) next();
  }

  private getBaseUrl(): string {
    return useSettingsStore.getState().serverUrl || '';
  }

  private getToken(): string | null {
    return useAuthStore.getState().token;
  }

  private async request<T>(
    endpoint: string,
    options: RequestInit = {}
  ): Promise<ApiResponse<T>> {
    const baseUrl = this.getBaseUrl();
    if (!baseUrl) {
      return {
        success: false,
        error: { code: 'NO_SERVER_URL', message: '未配置服务器地址' },
      };
    }

    let url = `${baseUrl}/api/${endpoint}`;
    const token = this.getToken();

    const method = String(options.method || 'GET').toUpperCase();

    const headers: Record<string, string> = {
      ...(options.headers as Record<string, string>),
    };

    const hasBody = options.body !== undefined && options.body !== null;
    if (hasBody || (method !== 'GET' && method !== 'HEAD')) {
      if (!headers['Content-Type']) {
        headers['Content-Type'] = 'application/json';
      }
    }

    if (token) {
      if (method === 'GET' || method === 'HEAD') {
        try {
          const urlObj = new URL(url);
          if (!urlObj.searchParams.has('token')) {
            urlObj.searchParams.set('token', token);
          }
          url = urlObj.toString();
        } catch {
          // ignore
        }
      } else {
        headers['Authorization'] = `Bearer ${token}`;
      }
    }

    const doRequest = async (): Promise<ApiResponse<T>> => {
      const maxRetries = 2;
      for (let attempt = 0; attempt <= maxRetries; attempt++) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 15000);

        try {
          const response = await fetch(url, {
            ...options,
            headers,
            signal: controller.signal,
          });

          const contentType = response.headers.get('content-type') || '';
          const isJson = contentType.includes('application/json');

          let parsed: any = null;
          if (isJson) {
            parsed = await response.json();
          } else {
            const text = await response.text();
            const snippet = text ? text.slice(0, 200) : '';
            parsed = {
              success: false,
              error: {
                code: 'NON_JSON_RESPONSE',
                message: snippet ? `非 JSON 响应: ${snippet}` : '非 JSON 响应',
              },
            };
          }

          if (!response.ok) {
            if (response.status === 401) {
              useAuthStore.getState().logout();
            }

            const retryable = [502, 503, 504].includes(response.status);
            if (retryable && attempt < maxRetries) {
              const delayMs = 500 * Math.pow(2, attempt);
              await new Promise((r) => setTimeout(r, delayMs));
              continue;
            }

            return {
              success: false,
              error: parsed?.error || {
                code: 'HTTP_ERROR',
                message: `HTTP ${response.status}`,
              },
            };
          }

          return parsed as ApiResponse<T>;
        } catch (error) {
          const isAbort = error instanceof Error && error.name === 'AbortError';
          const retryable = isAbort || (error instanceof Error && /Failed to fetch|NetworkError/i.test(error.message));
          if (retryable && attempt < maxRetries) {
            const delayMs = 500 * Math.pow(2, attempt);
            await new Promise((r) => setTimeout(r, delayMs));
            continue;
          }
          console.error('[SYNC_DEBUG] HTTP 请求失败:', error);
          return {
            success: false,
            error: {
              code: isAbort ? 'TIMEOUT' : 'NETWORK_ERROR',
              message: error instanceof Error ? error.message : '网络错误',
            },
          };
        } finally {
          clearTimeout(timeoutId);
        }
      }

      return {
        success: false,
        error: {
          code: 'NETWORK_ERROR',
          message: '网络错误',
        },
      };
    };

    const cacheKey = `${method} ${url}`;
    if (method === 'GET') {
      const existing = this.inflightGet.get(cacheKey);
      if (existing) {
        return existing as Promise<ApiResponse<T>>;
      }
    }

    await this.acquireSlot();
    try {
      const promise = doRequest();
      if (method === 'GET') {
        this.inflightGet.set(cacheKey, promise as Promise<ApiResponse<unknown>>);
      }
      const result = await promise;
      return result;
    } finally {
      if (method === 'GET') {
        this.inflightGet.delete(cacheKey);
      }
      this.releaseSlot();
    }
  }

  async get<T>(endpoint: string): Promise<ApiResponse<T>> {
    return this.request<T>(endpoint, { method: 'GET' });
  }

  async post<T>(endpoint: string, body?: unknown): Promise<ApiResponse<T>> {
    return this.request<T>(endpoint, {
      method: 'POST',
      body: body ? JSON.stringify(body) : undefined,
    });
  }

  async put<T>(endpoint: string, body?: unknown): Promise<ApiResponse<T>> {
    return this.request<T>(endpoint, {
      method: 'PUT',
      body: body ? JSON.stringify(body) : undefined,
    });
  }

  async delete<T>(endpoint: string): Promise<ApiResponse<T>> {
    return this.request<T>(endpoint, { method: 'DELETE' });
  }

  /**
   * 测试服务器连接
   * @param serverUrl 服务器地址
   * @returns 连接测试结果
   */
  async testConnection(serverUrl: string): Promise<{ success: boolean; message: string }> {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 5000); // 5秒超时

    try {
      const response = await fetch(`${serverUrl}/api/auth/me.php`, {
        method: 'GET',
        headers: { 'Content-Type': 'application/json' },
        signal: controller.signal,
      });

      clearTimeout(timeoutId);

      // 401 表示连接成功但未登录，其他 2xx/4xx 也算连接成功
      if (response.status === 401 || response.ok || response.status < 500) {
        return { success: true, message: '服务器连接成功' };
      }

      return { 
        success: false, 
        message: `服务器错误 (HTTP ${response.status})` 
      };
    } catch (error) {
      clearTimeout(timeoutId);
      
      if (error instanceof Error) {
        if (error.name === 'AbortError') {
          return { success: false, message: '连接超时，请检查服务器地址' };
        }
        // 网络错误
        if (error.message.includes('Failed to fetch') || error.message.includes('NetworkError')) {
          return { success: false, message: '无法连接服务器，请检查网络和地址' };
        }
        return { success: false, message: error.message };
      }
      
      return { success: false, message: '未知错误' };
    }
  }
}

export const http = new HttpClient();

export function getApiBaseUrl(): string {
  const serverUrl = useSettingsStore.getState().serverUrl || '';
  return serverUrl ? `${serverUrl}/api/` : '';
}
