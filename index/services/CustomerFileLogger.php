<?php

require_once __DIR__ . '/../core/db.php';

class CustomerFileLogger
{
    /**
     * @var callable|null
     */
    private $writer;

    public function __construct(?callable $writer = null)
    {
        $this->writer = $writer;
    }

    public function log(int $customerId, ?int $fileId, string $action, array $actor, array $extra = []): void
    {
        $this->writeLogEntry($customerId, $fileId, $action, $actor, $extra, 'customer_file');
    }

    public function folderUpload(int $customerId, array $actor, array $extra = []): void
    {
        $this->writeLogEntry($customerId, null, 'folder_upload', $actor, $extra, 'customer_folder_upload');
    }

    public function folderDownload(int $customerId, array $actor, array $extra = []): void
    {
        $this->writeLogEntry($customerId, null, 'folder_download', $actor, $extra, 'customer_folder_download');
    }

    private function writeLogEntry(int $customerId, ?int $fileId, string $action, array $actor, array $extra, string $targetType): void
    {
        if ($this->writer) {
            call_user_func($this->writer, $customerId, $fileId, $action, $actor, $extra);
            return;
        }

        $description = $fileId ? ($action . ' #' . $fileId) : $action;

        Db::execute(
            'INSERT INTO customer_logs (customer_id, file_id, action, actor_id, ip, extra, created_at)
             VALUES (:customer_id, :file_id, :action, :actor_id, :ip, :extra, :created_at)',
            [
                'customer_id' => $customerId,
                'file_id' => $fileId,
                'action' => $action,
                'actor_id' => $actor['id'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'extra' => $extra ? json_encode($extra) : null,
                'created_at' => time(),
            ]
        );

        Db::execute(
            'INSERT INTO operation_logs
                (user_id, module, action, target_type, target_id, customer_id, file_id,
                 description, extra, ip, user_agent, created_at)
             VALUES
                (:user_id, :module, :action, :target_type, :target_id, :customer_id, :file_id,
                 :description, :extra, :ip, :user_agent, :created_at)',
            [
                'user_id' => $actor['id'],
                'module' => 'customer_files',
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $fileId,
                'customer_id' => $customerId,
                'file_id' => $fileId,
                'description' => $description,
                'extra' => $extra ? json_encode($extra) : null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'created_at' => time(),
            ]
        );
    }
}

