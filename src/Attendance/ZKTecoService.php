<?php

namespace Administrator\Deno2\Attendance;

use PDO;

/**
 * Wrapper around the standalone ZKTecoDataPuller class.
 * Allows other modules to trigger pulls programmatically
 * without coupling to the CLI file.
 */
class ZKTecoService
{
    private PDO $db;
    private string $pullerScript;

    public function __construct(PDO $db)
    {
        $this->db           = $db;
        // Path is relative — works on any machine regardless of drive letter
        $this->pullerScript = dirname(__DIR__, 2) . '/attendance_device/zkteco_puller.php';
    }

    /**
     * Trigger a pull via CLI (non-blocking background process).
     * Returns the command string that was executed.
     */
    public function triggerAsyncPull(
        string $schedule = 'manual',
        ?string $date = null,
        ?int $deviceId = null
    ): string {
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 3);
        $args    = '--schedule=' . escapeshellarg($schedule)
                 . ' --doc_root=' . escapeshellarg($docRoot);

        if ($date) {
            $args .= ' --date=' . escapeshellarg($date);
        }
        if ($deviceId) {
            $args .= ' --device=' . escapeshellarg((string) $deviceId);
        }

        $cmd = 'php ' . escapeshellarg($this->pullerScript) . ' ' . $args;

        // Fire and forget — redirect both stdout and stderr to the log
        $logFile = dirname($this->pullerScript) . '/logs/zkteco/web_trigger_' . date('Y-m-d') . '.log';
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start /B $cmd >> " . escapeshellarg($logFile) . ' 2>&1', 'r'));
        } else {
            exec("$cmd >> " . escapeshellarg($logFile) . ' 2>&1 &');
        }

        return $cmd;
    }

    /**
     * Get pull history from the database.
     */
    public function getPullHistory(int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT
                pl.*,
                d.device_name, d.ip_address
            FROM zkteco_pull_log pl
            LEFT JOIN zkteco_devices d ON pl.device_id = d.id
            ORDER BY pl.completed_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all active ZKTeco devices.
     */
    public function getDevices(): array
    {
        return $this->db->query("
            SELECT * FROM zkteco_devices WHERE is_active = true ORDER BY priority
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get device status (last pull info).
     */
    public function getDeviceStatus(): array
    {
        return $this->db->query("
            SELECT * FROM v_zkteco_device_status
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}
