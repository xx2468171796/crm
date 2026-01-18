<?php

class ZipArchiveService
{
    private ZipArchive $zip;
    private string $zipPath;
    private array $tempFiles = [];
    private bool $finished = false;

    public function __construct(string $zipPath)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('服务器未启用 ZipArchive 扩展');
        }

        $this->zipPath = $zipPath;
        $this->zip = new ZipArchive();
        $opened = $this->zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException('无法创建压缩包: ' . $zipPath);
        }
    }

    /**
     * @param resource $stream
     */
    public function addStream($stream, string $zipInternalPath): void
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('无效的文件流');
        }

        $temp = tempnam(sys_get_temp_dir(), 'zip_entry_');
        if ($temp === false) {
            throw new RuntimeException('无法创建临时文件');
        }

        $destination = fopen($temp, 'wb');
        if ($destination === false) {
            throw new RuntimeException('无法写入临时文件');
        }

        stream_copy_to_stream($stream, $destination);
        fclose($destination);
        fclose($stream);

        $this->tempFiles[] = $temp;
        if (!$this->zip->addFile($temp, $zipInternalPath)) {
            throw new RuntimeException('写入压缩包失败: ' . $zipInternalPath);
        }
    }

    public function finish(): void
    {
        if ($this->finished) {
            return;
        }
        $this->zip->close();
        $this->cleanupTemps();
        $this->finished = true;
    }

    public function abort(): void
    {
        if (!$this->finished) {
            $this->zip->close();
        }
        $this->cleanupTemps();
        if (is_file($this->zipPath)) {
            @unlink($this->zipPath);
        }
        $this->finished = true;
    }

    private function cleanupTemps(): void
    {
        foreach ($this->tempFiles as $temp) {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
        $this->tempFiles = [];
    }

    public function __destruct()
    {
        if (!$this->finished) {
            $this->abort();
        }
    }
}

