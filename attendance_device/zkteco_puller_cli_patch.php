<?php
// ============================================================
// PATCH FOR zkteco_puller.php
// Replace the existing CLI block at the bottom of the file
// (the section starting with: if (php_sapi_name() === 'cli'))
// ============================================================

if (php_sapi_name() === 'cli') {

    // ----------------------------------------------------------
    // FIX: DOCUMENT_ROOT is empty when called via exec() on Windows.
    // zkteco_ajax.php passes --doc_root so we restore it here
    // before any require_once calls use it.
    // ----------------------------------------------------------
    $options = getopt('', ['date::', 'device::', 'schedule::', 'doc_root::']);

    // Restore DOCUMENT_ROOT if passed from the web process
    if (!empty($options['doc_root'])) {
        $_SERVER['DOCUMENT_ROOT'] = $options['doc_root'];
    }

    // Safety fallback: derive from __DIR__ if still empty
    // Adjust dirname depth to match your folder structure:
    // zkteco_puller.php lives in /deno2/attendance_device/
    // so two levels up reaches the web root
    if (empty($_SERVER['DOCUMENT_ROOT'])) {
        $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
    }

    $date      = $options['date']     ?? null;
    $device_id = $options['device']   ?? null;
    $schedule  = $options['schedule'] ?? null;

    // Validate schedule
    $valid_schedules = ['morning', 'midmorning', 'afternoon', 'evening', 'night'];
    if ($schedule && !in_array($schedule, $valid_schedules)) {
        echo "Invalid schedule. Valid options: " . implode(', ', $valid_schedules) . "\n";
        exit(1);
    }

    try {
        $puller = new ZKTecoDataPuller($conn, $schedule);
        $stats  = $puller->pullAttendance($date, $device_id);

        echo "\n=== Pull Summary ===\n";
        echo "Inserted:  {$stats['inserted']}\n";
        echo "Updated:   {$stats['updated']}\n";
        echo "Skipped:   {$stats['skipped']}\n";
        echo "Errors:    {$stats['errors']}\n";
        echo "Employees: " . count($stats['employees_processed']) . "\n";

        exit(0);

    } catch (Exception $e) {
        echo "FATAL ERROR: " . $e->getMessage() . "\n";
        echo "Trace:\n" . $e->getTraceAsString() . "\n";
        exit(1);
    }
}
?>
