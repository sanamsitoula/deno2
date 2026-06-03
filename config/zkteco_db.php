<?php
/**
 * Second database connection — ZKTecePuller's PostgreSQL database.
 * This is the Python puller's DB (separate from press_jemc).
 *
 * Tables: devices, employees, attendance_logs, pull_sessions
 */
try {
    $zk_conn = new PDO(
        'pgsql:host=localhost;port=5432;dbname=zkteco',
        'postgres',
        'Nepal@123',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $zk_db_status = true;
} catch (PDOException $e) {
    $zk_db_status = false;
    $zk_conn      = null;
    // Non-fatal — ZKTeco DB may not be set up yet
}
