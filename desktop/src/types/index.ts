export interface User {
  id: number;
  username: string;
  name: string;
  role: string;
  department_id: number | null;
}

export interface LoginResponse {
  success: boolean;
  data?: {
    token: string;
    expire_at: number;
    user: User;
  };
  error?: {
    code: string;
    message: string;
  };
}

export interface Group {
  group_code: string;
  group_name: string | null;
  customer_id: number;
  customer_name: string;
  owner_user_id: number;
  owner_name: string | null;
  create_time: number | null;
  resource_counts: {
    works: number;
    models: number;
    customer: number;
  };
}

export interface GroupsResponse {
  success: boolean;
  data?: {
    items: Group[];
    total: number;
    page: number;
    per_page: number;
  };
  error?: {
    code: string;
    message: string;
  };
}

export interface Resource {
  id: number;
  rel_path: string;
  filename: string;
  storage_key: string;
  filesize: number;
  mime_type: string | null;
  file_ext: string | null;
  etag: string | null;
  uploaded_by: number;
  uploaded_at: number | null;
  updated_at: number | null;
}

export interface ResourcesResponse {
  success: boolean;
  data?: {
    group_code: string;
    asset_type: string;
    items: Resource[];
    total: number;
    page: number;
    per_page: number;
  };
  error?: {
    code: string;
    message: string;
  };
}

export type AssetType = 'works' | 'models' | 'customer';

export interface UploadTask {
  id: string;
  groupCode: string;
  assetType: AssetType;
  relPath: string;
  filename: string;
  filesize: number;
  uploadId?: string;
  storageKey?: string;
  status: 'pending' | 'uploading' | 'paused' | 'completed' | 'failed';
  progress: number;
  uploadedParts: number;
  totalParts: number;
  speed: number;
  eta: number;
  error?: string;
  createdAt: number;
  updatedAt: number;
}

export interface DownloadTask {
  id: string;
  groupCode: string;
  assetType: AssetType;
  relPath: string;
  filename: string;
  filesize: number;
  status: 'pending' | 'downloading' | 'paused' | 'completed' | 'failed';
  progress: number;
  speed: number;
  eta: number;
  error?: string;
  createdAt: number;
  updatedAt: number;
}

export interface Settings {
  rootDir: string;
  serverUrl: string;
  autoSync: boolean;
  syncInterval: number;
  maxConcurrentUploads: number;
  maxConcurrentDownloads: number;
  partSize: number;
  startOnBoot: boolean;
  minimizeToTray: boolean;
}
