import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { X, ChevronLeft, ChevronRight, Download, Loader2, RotateCw, ZoomIn, ZoomOut } from 'lucide-react';
import { Document, Page, pdfjs } from 'react-pdf';
import { useSettingsStore } from '@/stores/settings';
import { useAuthStore } from '@/stores/auth';

// 配置 PDF.js worker
pdfjs.GlobalWorkerOptions.workerSrc = `//unpkg.com/pdfjs-dist@${pdfjs.version}/build/pdf.worker.min.mjs`;

export interface PreviewFile {
  id: number;
  filename: string;
  file_path: string;
  file_size?: number;
  mime_type?: string;
  download_url?: string;
}

interface FilePreviewModalProps {
  open: boolean;
  onClose: () => void;
  file: PreviewFile | null;
  files?: PreviewFile[];
  onNavigate?: (file: PreviewFile) => void;
}

type PreviewFileType = 'image' | 'video' | 'audio' | 'pdf' | 'unknown';

function getFileType(filename: string): PreviewFileType {
  const ext = filename.split('.').pop()?.toLowerCase() || '';
  // 只保留图片预览，关闭PDF和视频预览
  if (['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'].includes(ext)) return 'image';
  // if (['mp4', 'webm', 'ogg', 'mov', 'avi'].includes(ext)) return 'video';
  // if (['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a'].includes(ext)) return 'audio';
  // if (ext === 'pdf') return 'pdf';
  return 'unknown';
}

export default function FilePreviewModal({
  open,
  onClose,
  file,
  files = [],
  onNavigate,
}: FilePreviewModalProps) {
  const { serverUrl } = useSettingsStore();
  const { token } = useAuthStore();
  
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [scale, setScale] = useState(1);
  const [rotation, setRotation] = useState(0);
  const [position, setPosition] = useState({ x: 0, y: 0 });
  const [isDragging, setIsDragging] = useState(false);
  const [isSpacePressed, setIsSpacePressed] = useState(false);
  const dragStartRef = useRef<{ x: number; y: number; posX: number; posY: number } | null>(null);
  const containerRef = useRef<HTMLDivElement>(null);

  const fileType = useMemo(() => file ? getFileType(file.filename) : 'unknown', [file?.filename]);

  const currentIndex = useMemo(() => {
    if (!file || files.length === 0) return -1;
    return files.findIndex((f) => f.id === file.id);
  }, [files, file?.id]);
  
  const hasPrev = currentIndex > 0;
  const hasNext = currentIndex >= 0 && currentIndex < files.length - 1;

  // 加载预览
  useEffect(() => {
    if (!open || !file) return;

    const loadPreview = async () => {
      try {
        setLoading(true);
        setError(null);
        setScale(1);
        setRotation(0);
        setPosition({ x: 0, y: 0 });

        // 通过后端代理获取文件，避免CORS问题
        let targetUrl = '';
        
        console.log('[Preview] 开始加载预览', { file });
        
        if (file.download_url) {
          // 有预签名URL，通过后端代理获取
          targetUrl = file.download_url;
          console.log('[Preview] 使用download_url:', targetUrl.substring(0, 100) + '...');
        } else if (file.file_path) {
          // 获取预签名URL
          console.log('[Preview] 获取预签名URL, file_path:', file.file_path);
          const response = await fetch(`${serverUrl}/api/desktop_download.php`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              ...(token ? { Authorization: `Bearer ${token}` } : {}),
            },
            body: JSON.stringify({ storage_key: file.file_path }),
          });
          const data = await response.json();
          console.log('[Preview] desktop_download响应:', data);
          if (data.success && data.data?.presigned_url) {
            targetUrl = data.data.presigned_url;
          } else {
            throw new Error(data.error?.message || data.error || '获取预览URL失败');
          }
        } else {
          throw new Error('缺少文件路径信息');
        }
        
        // 通过后端代理获取文件内容，避免CORS问题
        const proxyUrl = `${serverUrl}/api/desktop_file_proxy.php?url=${encodeURIComponent(targetUrl)}`;
        console.log('[Preview] 代理请求:', proxyUrl.substring(0, 150) + '...');
        
        const fileResponse = await fetch(proxyUrl, {
          headers: token ? { Authorization: `Bearer ${token}` } : {},
        });
        
        console.log('[Preview] 代理响应状态:', fileResponse.status, fileResponse.statusText);
        
        if (!fileResponse.ok) {
          const errorText = await fileResponse.text();
          console.error('[Preview] 代理错误:', errorText);
          throw new Error(`获取文件失败: ${fileResponse.status} - ${errorText}`);
        }
        
        const blob = await fileResponse.blob();
        console.log('[Preview] 获取blob成功, size:', blob.size, 'type:', blob.type);
        const objectUrl = URL.createObjectURL(blob);
        setPreviewUrl(objectUrl);
      } catch (err) {
        console.error('预览加载失败:', err);
        setError('预览加载失败');
      } finally {
        setLoading(false);
      }
    };

    loadPreview();

  }, [open, file?.id, file?.file_path, serverUrl, token]);

  const zoomIn = useCallback(() => setScale((s) => Math.min(s + 0.25, 5)), []);
  const zoomOut = useCallback(() => setScale((s) => Math.max(s - 0.25, 0.25)), []);
  const rotate = useCallback(() => setRotation((r) => (r + 90) % 360), []);

  const goPrev = useCallback(() => {
    if (hasPrev && onNavigate) onNavigate(files[currentIndex - 1]);
  }, [hasPrev, onNavigate, files, currentIndex]);

  const goNext = useCallback(() => {
    if (hasNext && onNavigate) onNavigate(files[currentIndex + 1]);
  }, [hasNext, onNavigate, files, currentIndex]);

  const resetView = useCallback(() => {
    setScale(1);
    setRotation(0);
    setPosition({ x: 0, y: 0 });
  }, []);

  // 键盘事件
  useEffect(() => {
    if (!open) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.code === 'Space') {
        e.preventDefault();
        setIsSpacePressed(true);
        return;
      }
      switch (e.key) {
        case 'Escape':
          onClose();
          break;
        case 'ArrowLeft':
          goPrev();
          break;
        case 'ArrowRight':
          goNext();
          break;
        case '+':
        case '=':
          zoomIn();
          break;
        case '-':
          zoomOut();
          break;
        case 'r':
        case 'R':
          rotate();
          break;
        case '0':
          resetView();
          break;
      }
    };

    const handleKeyUp = (e: KeyboardEvent) => {
      if (e.code === 'Space') {
        setIsSpacePressed(false);
        setIsDragging(false);
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    window.addEventListener('keyup', handleKeyUp);
    return () => {
      window.removeEventListener('keydown', handleKeyDown);
      window.removeEventListener('keyup', handleKeyUp);
    };
  }, [open, onClose, goPrev, goNext, zoomIn, zoomOut, rotate, resetView]);

  // 滚轮缩放
  useEffect(() => {
    if (!open) return;
    const container = containerRef.current;
    if (!container) return;

    const handleWheel = (e: WheelEvent) => {
      if (fileType === 'image') {
        e.preventDefault();
        if (e.deltaY < 0) zoomIn();
        else zoomOut();
      }
    };

    container.addEventListener('wheel', handleWheel, { passive: false });
    return () => container.removeEventListener('wheel', handleWheel);
  }, [open, zoomIn, zoomOut, fileType]);

  const handleMouseDown = useCallback((e: React.MouseEvent) => {
    if (isSpacePressed && fileType === 'image') {
      e.preventDefault();
      setIsDragging(true);
      dragStartRef.current = {
        x: e.clientX,
        y: e.clientY,
        posX: position.x,
        posY: position.y,
      };
    }
  }, [isSpacePressed, position, fileType]);

  const handleMouseMove = useCallback((e: React.MouseEvent) => {
    if (isDragging && dragStartRef.current) {
      const dx = e.clientX - dragStartRef.current.x;
      const dy = e.clientY - dragStartRef.current.y;
      setPosition({
        x: dragStartRef.current.posX + dx,
        y: dragStartRef.current.posY + dy,
      });
    }
  }, [isDragging]);

  const handleMouseUp = useCallback(() => {
    setIsDragging(false);
    dragStartRef.current = null;
  }, []);

  const downloadFile = useCallback(async () => {
    if (!previewUrl || !file) return;
    const a = document.createElement('a');
    a.href = previewUrl;
    a.download = file.filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  }, [previewUrl, file?.filename]);

  if (!open || !file) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/90">
      {/* 头部工具栏 */}
      <div className="absolute top-0 left-0 right-0 flex items-center justify-between px-4 py-3 bg-black/50 z-10">
        <div className="text-white text-sm truncate max-w-[40%]" title={file.filename}>
          {file.filename}
          {files.length > 1 && currentIndex >= 0 && (
            <span className="ml-2 text-white/60">({currentIndex + 1} / {files.length})</span>
          )}
        </div>

        <div className="flex items-center gap-2">
          {fileType === 'image' && (
            <>
              <button onClick={zoomOut} className="p-2 rounded hover:bg-white/10 text-white" title="缩小 (-)">
                <ZoomOut className="h-5 w-5" />
              </button>
              <span className="text-white text-sm min-w-[60px] text-center">{Math.round(scale * 100)}%</span>
              <button onClick={zoomIn} className="p-2 rounded hover:bg-white/10 text-white" title="放大 (+)">
                <ZoomIn className="h-5 w-5" />
              </button>
              <button onClick={rotate} className="p-2 rounded hover:bg-white/10 text-white" title="旋转 (R)">
                <RotateCw className="h-5 w-5" />
              </button>
              <div className="w-px h-6 bg-white/20 mx-2" />
            </>
          )}

          <button onClick={downloadFile} className="p-2 rounded hover:bg-white/10 text-white" title="下载">
            <Download className="h-5 w-5" />
          </button>
          <button onClick={onClose} className="p-2 rounded hover:bg-white/10 text-white" title="关闭 (ESC)">
            <X className="h-5 w-5" />
          </button>
        </div>
      </div>

      {/* 预览内容区域 */}
      <div 
        ref={containerRef}
        className="flex-1 flex items-center justify-center w-full h-full pt-14 pb-10 overflow-hidden"
        style={{ cursor: isSpacePressed && fileType === 'image' ? (isDragging ? 'grabbing' : 'grab') : 'default' }}
        onMouseDown={handleMouseDown}
        onMouseMove={handleMouseMove}
        onMouseUp={handleMouseUp}
        onMouseLeave={handleMouseUp}
      >
        {/* 导航按钮 */}
        {hasPrev && (
          <button 
            onClick={goPrev} 
            className="absolute left-4 z-10 p-3 rounded-full bg-black/50 hover:bg-black/70 text-white transition-colors"
            title="上一张 (←)"
          >
            <ChevronLeft className="h-8 w-8" />
          </button>
        )}

        {hasNext && (
          <button 
            onClick={goNext} 
            className="absolute right-4 z-10 p-3 rounded-full bg-black/50 hover:bg-black/70 text-white transition-colors"
            title="下一张 (→)"
          >
            <ChevronRight className="h-8 w-8" />
          </button>
        )}

        {/* 加载/错误/内容 */}
        {loading ? (
          <div className="flex items-center justify-center">
            <Loader2 className="h-12 w-12 animate-spin text-white" />
          </div>
        ) : error ? (
          <div className="text-center p-8">
            <p className="text-red-400 text-lg">{error}</p>
            <button onClick={downloadFile} className="mt-4 px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600">
              下载文件
            </button>
          </div>
        ) : previewUrl ? (
          <>
            {fileType === 'image' && (
              <img
                src={previewUrl}
                alt={file.filename}
                className="max-w-full max-h-full object-contain transition-transform duration-200"
                style={{ transform: `translate(${position.x}px, ${position.y}px) scale(${scale}) rotate(${rotation}deg)` }}
                draggable={false}
              />
            )}

            {fileType === 'video' && (
              <video src={previewUrl} controls autoPlay className="max-w-full max-h-full rounded" />
            )}

            {fileType === 'audio' && (
              <div className="flex flex-col items-center gap-6 p-8 bg-white/5 rounded-xl">
                <div className="w-32 h-32 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center">
                  <span className="text-4xl">🎵</span>
                </div>
                <div className="text-white text-lg font-medium text-center max-w-md truncate" title={file.filename}>
                  {file.filename}
                </div>
                <audio src={previewUrl} controls autoPlay className="w-full max-w-md" />
              </div>
            )}

            {fileType === 'pdf' && <PdfViewer url={previewUrl} onDownload={downloadFile} />}

            {fileType === 'unknown' && (
              <div className="text-center p-8">
                <p className="text-white/60 text-lg">此文件类型暂不支持预览</p>
                <button onClick={downloadFile} className="mt-4 px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600">
                  下载文件
                </button>
              </div>
            )}
          </>
        ) : null}
      </div>

      {/* 底部提示 */}
      <div className="absolute bottom-0 left-0 right-0 text-center py-2 text-white/40 text-xs bg-black/30">
        {fileType === 'image' && '空格+拖动平移 | 滚轮缩放 | +/- 缩放 | R 旋转 | 0 重置 | ←/→ 切换 | ESC 关闭'}
        {fileType === 'pdf' && '滚动查看 | Ctrl+滚轮缩放 | ←/→ 翻页 | ESC 关闭'}
        {fileType !== 'image' && fileType !== 'pdf' && '←/→ 切换 | ESC 关闭'}
      </div>
    </div>
  );
}

// PDF 预览组件
function PdfViewer({ url, onDownload }: { url: string | null; onDownload: () => void }) {
  const [numPages, setNumPages] = useState<number>(0);
  const [pageNumber, setPageNumber] = useState(1);
  const [pdfScale, setPdfScale] = useState(1.0);
  const [containerWidth, setContainerWidth] = useState(800);
  const containerRef = useRef<HTMLDivElement>(null);

  const onDocumentLoadSuccess = ({ numPages }: { numPages: number }) => {
    setNumPages(numPages);
    setPageNumber(1);
  };

  const goToPrevPage = useCallback(() => setPageNumber((p) => Math.max(p - 1, 1)), []);
  const goToNextPage = useCallback(() => setPageNumber((p) => Math.min(p + 1, numPages)), [numPages]);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'ArrowLeft') goToPrevPage();
      if (e.key === 'ArrowRight') goToNextPage();
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [goToPrevPage, goToNextPage]);

  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;

    const updateWidth = () => {
      if (containerRef.current) {
        setContainerWidth(containerRef.current.clientWidth - 40);
      }
    };
    updateWidth();
    window.addEventListener('resize', updateWidth);

    const handleWheel = (e: WheelEvent) => {
      if (e.ctrlKey) {
        e.preventDefault();
        if (e.deltaY < 0) setPdfScale(s => Math.min(s + 0.1, 3));
        else setPdfScale(s => Math.max(s - 0.1, 0.5));
      }
    };

    container.addEventListener('wheel', handleWheel, { passive: false });
    return () => {
      container.removeEventListener('wheel', handleWheel);
      window.removeEventListener('resize', updateWidth);
    };
  }, []);

  if (!url) return null;

  return (
    <div ref={containerRef} className="flex flex-col items-center h-full w-full overflow-auto py-4">
      <Document
        file={url}
        onLoadSuccess={onDocumentLoadSuccess}
        loading={
          <div className="flex items-center justify-center h-full">
            <Loader2 className="h-12 w-12 animate-spin text-white" />
          </div>
        }
        error={
          <div className="flex flex-col items-center justify-center h-full gap-4 p-8">
            <p className="text-red-400">PDF 加载失败</p>
            <button onClick={onDownload} className="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600">
              下载查看
            </button>
          </div>
        }
      >
        <Page
          pageNumber={pageNumber}
          width={containerWidth * pdfScale}
          renderTextLayer={false}
          renderAnnotationLayer={false}
        />
      </Document>
      
      {numPages > 0 && (
        <div className="fixed bottom-16 left-1/2 -translate-x-1/2 flex items-center gap-4 bg-black/70 px-4 py-2 rounded-full">
          <button
            onClick={goToPrevPage}
            disabled={pageNumber <= 1}
            className="p-1 text-white disabled:opacity-30 hover:bg-white/10 rounded"
          >
            <ChevronLeft className="h-5 w-5" />
          </button>
          <span className="text-white text-sm min-w-[80px] text-center">
            {pageNumber} / {numPages}
          </span>
          <button
            onClick={goToNextPage}
            disabled={pageNumber >= numPages}
            className="p-1 text-white disabled:opacity-30 hover:bg-white/10 rounded"
          >
            <ChevronRight className="h-5 w-5" />
          </button>
          <div className="w-px h-5 bg-white/30" />
          <button
            onClick={() => setPdfScale((s) => Math.max(s - 0.2, 0.5))}
            className="p-1 text-white hover:bg-white/10 rounded"
          >
            <ZoomOut className="h-4 w-4" />
          </button>
          <span className="text-white text-xs">{Math.round(pdfScale * 100)}%</span>
          <button
            onClick={() => setPdfScale((s) => Math.min(s + 0.2, 3))}
            className="p-1 text-white hover:bg-white/10 rounded"
          >
            <ZoomIn className="h-4 w-4" />
          </button>
          <div className="w-px h-5 bg-white/30" />
          <button onClick={onDownload} className="p-1 text-white hover:bg-white/10 rounded" title="下载">
            <Download className="h-4 w-4" />
          </button>
        </div>
      )}
    </div>
  );
}
