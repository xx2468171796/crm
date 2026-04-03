/**
 * WebSocket 实时通知服务
 */

export interface WebSocketNotification {
  type: 'notification';
  id?: number;
  title: string;
  content: string;
  urgency: 'low' | 'normal' | 'high';
  data?: {
    project_id?: number;
    project_name?: string;
    task_id?: number;
  };
  created_at: string;
}

export interface WebSocketMessage {
  type: string;
  [key: string]: unknown;
}

type ConnectionStatus = 'disconnected' | 'connecting' | 'connected' | 'authenticated';

class WebSocketService {
  private ws: WebSocket | null = null;
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  private pingTimer: ReturnType<typeof setInterval> | null = null;
  private token: string = '';
  private wsUrl: string = '';
  private status: ConnectionStatus = 'disconnected';
  private reconnectAttempts: number = 0;
  private maxReconnectAttempts: number = 10;
  private reconnectDelay: number = 3000;
  
  private onNotificationCallbacks: ((notification: WebSocketNotification) => void)[] = [];
  private onStatusChangeCallbacks: ((status: ConnectionStatus) => void)[] = [];
  
  /**
   * 连接到 WebSocket 服务
   */
  connect(wsUrl: string, token: string): void {
    if (this.ws && this.status !== 'disconnected') {
      console.log('[WebSocket] 已连接，跳过');
      return;
    }
    
    this.wsUrl = wsUrl;
    this.token = token;
    this.status = 'connecting';
    this.notifyStatusChange();
    
    try {
      console.log(`[WebSocket] 连接到 ${wsUrl}`);
      this.ws = new WebSocket(wsUrl);
      
      this.ws.onopen = () => {
        console.log('[WebSocket] 连接成功，发送认证');
        this.status = 'connected';
        this.reconnectAttempts = 0;
        this.notifyStatusChange();
        
        // 发送认证消息
        this.send({
          type: 'auth',
          token: `Bearer ${token}`
        });
        
        // 启动心跳
        this.startPing();
      };
      
      this.ws.onmessage = (event) => {
        this.handleMessage(event.data);
      };
      
      this.ws.onclose = (event) => {
        console.log(`[WebSocket] 连接关闭: ${event.code} ${event.reason}`);
        this.cleanup();
        this.scheduleReconnect();
      };
      
      this.ws.onerror = (error) => {
        console.error('[WebSocket] 错误:', error);
      };
      
    } catch (error) {
      console.error('[WebSocket] 连接失败:', error);
      this.cleanup();
      this.scheduleReconnect();
    }
  }
  
  /**
   * 断开连接
   */
  disconnect(): void {
    console.log('[WebSocket] 主动断开');
    this.cleanup();
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
  }
  
  /**
   * 发送消息
   */
  private send(message: WebSocketMessage): void {
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(message));
    }
  }
  
  /**
   * 处理接收到的消息
   */
  private handleMessage(data: string): void {
    try {
      const message = JSON.parse(data) as WebSocketMessage;
      
      switch (message.type) {
        case 'welcome':
          console.log('[WebSocket] 收到欢迎消息');
          break;
          
        case 'auth_result':
          if (message.success) {
            console.log(`[WebSocket] 认证成功, user_id: ${message.user_id}`);
            this.status = 'authenticated';
            this.notifyStatusChange();
          } else {
            console.error(`[WebSocket] 认证失败: ${message.message}`);
            this.disconnect();
          }
          break;
          
        case 'notification':
          console.log('[WebSocket] 收到通知:', message);
          this.onNotificationCallbacks.forEach(cb => cb(message as unknown as WebSocketNotification));
          break;
          
        case 'pong':
          // 心跳响应，无需处理
          break;
          
        case 'kicked':
          console.log('[WebSocket] 被踢出:', message.message);
          this.disconnect();
          break;
          
        default:
          console.log('[WebSocket] 未知消息类型:', message.type);
      }
    } catch (error) {
      console.error('[WebSocket] 消息解析失败:', error);
    }
  }
  
  /**
   * 启动心跳
   */
  private startPing(): void {
    this.stopPing();
    this.pingTimer = setInterval(() => {
      this.send({ type: 'ping' });
    }, 30000);
  }
  
  /**
   * 停止心跳
   */
  private stopPing(): void {
    if (this.pingTimer) {
      clearInterval(this.pingTimer);
      this.pingTimer = null;
    }
  }
  
  /**
   * 清理连接
   */
  private cleanup(): void {
    this.stopPing();
    if (this.ws) {
      this.ws.onopen = null;
      this.ws.onmessage = null;
      this.ws.onclose = null;
      this.ws.onerror = null;
      if (this.ws.readyState === WebSocket.OPEN) {
        this.ws.close();
      }
      this.ws = null;
    }
    this.status = 'disconnected';
    this.notifyStatusChange();
  }
  
  /**
   * 安排重连
   */
  private scheduleReconnect(): void {
    if (this.reconnectAttempts >= this.maxReconnectAttempts) {
      console.log('[WebSocket] 达到最大重连次数，停止重连');
      return;
    }
    
    const delay = this.reconnectDelay * Math.pow(1.5, this.reconnectAttempts);
    console.log(`[WebSocket] ${delay}ms 后重连 (第 ${this.reconnectAttempts + 1} 次)`);
    
    this.reconnectTimer = setTimeout(() => {
      this.reconnectAttempts++;
      this.connect(this.wsUrl, this.token);
    }, delay);
  }
  
  /**
   * 通知状态变化
   */
  private notifyStatusChange(): void {
    this.onStatusChangeCallbacks.forEach(cb => cb(this.status));
  }
  
  /**
   * 注册通知回调
   */
  onNotification(callback: (notification: WebSocketNotification) => void): () => void {
    this.onNotificationCallbacks.push(callback);
    return () => {
      this.onNotificationCallbacks = this.onNotificationCallbacks.filter(cb => cb !== callback);
    };
  }
  
  /**
   * 注册状态变化回调
   */
  onStatusChange(callback: (status: ConnectionStatus) => void): () => void {
    this.onStatusChangeCallbacks.push(callback);
    return () => {
      this.onStatusChangeCallbacks = this.onStatusChangeCallbacks.filter(cb => cb !== callback);
    };
  }
  
  /**
   * 获取当前状态
   */
  getStatus(): ConnectionStatus {
    return this.status;
  }
}

/**
 * 从服务端 API 获取 WebSocket 配置
 */
export const fetchWsConfig = async (serverUrl: string): Promise<{ enabled: boolean; url: string }> => {
  try {
    // 使用统一 HTTP 客户端（自动携带 Authorization header）
    const { http } = await import('@/lib/http');
    const result = await http.get<{ websocket: { enabled: boolean; url: string } }>('desktop_config.php');
    if (result.success && result.data?.websocket) {
      return {
        enabled: result.data.websocket.enabled,
        url: result.data.websocket.url,
      };
    }
  } catch (e) {
    console.error('[WebSocket] 获取配置失败:', e);
  }
  // 降级：使用本地推断
  return {
    enabled: true,
    url: getWsUrl(serverUrl),
  };
};

/**
 * 从 serverUrl 自动推断 WebSocket URL（降级方案）
 * - 公网环境 (https://domain.com) → wss://domain.com/ws
 * - 内网环境 (http://192.168.x.x) → ws://192.168.x.x:8300
 */
export const getWsUrl = (serverUrl: string): string => {
  try {
    const url = new URL(serverUrl);
    const protocol = url.protocol === 'https:' ? 'wss:' : 'ws:';
    
    // 判断是否为内网地址
    const isLocalNetwork = 
      url.hostname === 'localhost' ||
      url.hostname === '127.0.0.1' ||
      url.hostname.startsWith('192.168.') ||
      url.hostname.startsWith('10.') ||
      url.hostname.endsWith('.local') ||
      url.hostname.endsWith('.test');
    
    if (isLocalNetwork) {
      // 内网环境直接连接 8300 端口
      return `${protocol}//${url.hostname}:8300`;
    }
    
    // 公网环境使用 /ws 路径（通过 Nginx 反向代理）
    return `${protocol}//${url.host}/ws`;
  } catch {
    // URL 解析失败，返回默认值
    return 'ws://localhost:8300';
  }
};

// 导出单例
export const websocketService = new WebSocketService();
export type { ConnectionStatus };
