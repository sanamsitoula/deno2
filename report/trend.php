<?php
// ═══════════════════════════════════════════════════════════════════════════
// CALENDAR PRODUCTION REPORT — v5
//   • Correct FP vs BP logic (no "sum of formas = books made")
//   • Per-forma completion tracking with bottleneck detection
//   • BS-only Nepali date format in Trends (day-wise: 2083-02-07)
//   • BS fiscal-year quarters for time grouping
//   • Manpower tab: both AD + BS dates, operators, machines, shifts
//   • Time-bucket grouping: day / month / quarter / 6mo / year
// ═══════════════════════════════════════════════════════════════════════════

$is_ajax = isset($_GET['ajax']) &&
           in_array($_GET['ajax'], ['month_data','day_detail','trend_analysis','manpower'], true);

if ($is_ajax) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');

    // ════════════════════════════════════════════════════════════════
    // AJAX: month_data — daily totals + machine alerts
    // ════════════════════════════════════════════════════════════════
    if ($_GET['ajax'] === 'month_data') {
        $year      = (int)($_GET['year']        ?? date('Y'));
        $month     = (int)($_GET['month']       ?? date('n'));
        $fy_id     = (int)($_GET['fiscal_year'] ?? 0);
        $book_code = trim($_GET['book_code']    ?? '');
        $date_from = trim($_GET['date_from']    ?? '');
        $date_to   = trim($_GET['date_to']      ?? '');

        $df = $date_from ?: sprintf('%04d-%02d-01', $year, $month);
        $dt = $date_to   ?: date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $month)));

        try {
            // ── Forma Printing (FP qty is intermediate, NOT book count) ─
            $fp_w = ['fp.created_date::date BETWEEN :df AND :dt', 'fp.status = true'];
            $fp_p = [':df' => $df, ':dt' => $dt];
            if ($fy_id)     { $fp_w[] = 'fp.fiscal_year_id = :fy'; $fp_p[':fy'] = $fy_id; }
            if ($book_code) { $fp_w[] = 'b.book_code = :bc';       $fp_p[':bc'] = $book_code; }

            $st = $conn->prepare("
                SELECT fp.created_date::date AS day,
                       COALESCE(SUM(fp.fp_printqty),0) AS fp_total,
                       COUNT(DISTINCT jt.id)            AS jt_count,
                       STRING_AGG(DISTINCT b.book_code, ', ' ORDER BY b.book_code) AS book_codes
                FROM forma_printing fp
                JOIN job_ticket jt ON jt.id    = fp.jt_id
                JOIN books      b  ON b.book_id = jt.book_id
                WHERE " . implode(' AND ', $fp_w) . "
                GROUP BY fp.created_date::date
            ");
            foreach ($fp_p as $k => $v) $st->bindValue($k, $v);
            $st->execute();
            $fp_data = array_column($st->fetchAll(PDO::FETCH_ASSOC), null, 'day');

            // ── Book Packing (BP qty = actual books packed) ─────────────
            $bp_w = ['bp.created_date::date BETWEEN :df AND :dt', 'bp.status = true'];
            $bp_p = [':df' => $df, ':dt' => $dt];
            if ($fy_id)     { $bp_w[] = 'bp.fiscal_year_id = :fy'; $bp_p[':fy'] = $fy_id; }
            if ($book_code) { $bp_w[] = 'bp.book_code = :bc';      $bp_p[':bc'] = $book_code; }

            $st2 = $conn->prepare("
                SELECT bp.created_date::date AS day,
                       COALESCE(SUM(bp.p_qty),0) AS bp_total
                FROM book_packing bp
                WHERE " . implode(' AND ', $bp_w) . "
                GROUP BY bp.created_date::date
            ");
            foreach ($bp_p as $k => $v) $st2->bindValue($k, $v);
            $st2->execute();
            $bp_data = array_column($st2->fetchAll(PDO::FETCH_ASSOC), null, 'day');

            // ── Deno ────────────────────────────────────────────────────
            $dn_w = ["TO_DATE(d.deno_date_eng,'YYYY.MM.DD') BETWEEN :df AND :dt",
                     'd.deleted_at IS NULL'];
            $dn_p = [':df' => $df, ':dt' => $dt];
            if ($book_code) { $dn_w[] = 'd.book_code = :bc'; $dn_p[':bc'] = $book_code; }

            $st3 = $conn->prepare("
                SELECT TO_DATE(d.deno_date_eng,'YYYY.MM.DD') AS day,
                       COALESCE(SUM(d.total_qty),0)          AS deno_total
                FROM deno d
                WHERE " . implode(' AND ', $dn_w) . "
                GROUP BY TO_DATE(d.deno_date_eng,'YYYY.MM.DD')
            ");
            foreach ($dn_p as $k => $v) $st3->bindValue($k, $v);
            $st3->execute();
            $dn_data = array_column($st3->fetchAll(PDO::FETCH_ASSOC), null, 'day');

            // ── D2M ─────────────────────────────────────────────────────
            $d2_w = ['dm.eng_date::date BETWEEN :df AND :dt', 'dm.deleted_at IS NULL'];
            $d2_p = [':df' => $df, ':dt' => $dt];
            if ($book_code) { $d2_w[] = 'di.book_code = :bc'; $d2_p[':bc'] = $book_code; }

            $st4 = $conn->prepare("
                SELECT dm.eng_date::date AS day,
                       COALESCE(SUM(di.total_qty),0) AS d2m_total
                FROM d2m_items di
                JOIN d2m dm ON dm.id = di.d2m_id
                WHERE " . implode(' AND ', $d2_w) . "
                GROUP BY dm.eng_date::date
            ");
            foreach ($d2_p as $k => $v) $st4->bindValue($k, $v);
            $st4->execute();
            $d2_data = array_column($st4->fetchAll(PDO::FETCH_ASSOC), null, 'day');

            // ── Machine trend (alerts) ──────────────────────────────────
            $trend_from = date('Y-m-d', strtotime($df . ' -14 days'));
            $mc_w = ['fp.created_date::date BETWEEN :tdf AND :tdt', 'fp.status = true'];
            $mc_p = [':tdf' => $trend_from, ':tdt' => $dt];
            if ($fy_id) { $mc_w[] = 'fp.fiscal_year_id = :fy'; $mc_p[':fy'] = $fy_id; }

            $st5 = $conn->prepare("
                SELECT fp.created_date::date AS day,
                       m.machine_name,
                       COALESCE(SUM(fp.fp_printqty),0) AS machine_qty
                FROM forma_printing fp
                LEFT JOIN machines m ON m.id = fp.machine_id
                WHERE " . implode(' AND ', $mc_w) . "
                GROUP BY fp.created_date::date, m.machine_name
            ");
            foreach ($mc_p as $k => $v) $st5->bindValue($k, $v);
            $st5->execute();
            $mc_trend_raw = $st5->fetchAll(PDO::FETCH_ASSOC);

            $machine_daily = [];
            foreach ($mc_trend_raw as $row) {
                $mn = $row['machine_name'] ?: 'Unknown';
                $machine_daily[$mn][$row['day']] = (int)$row['machine_qty'];
            }
            $machine_alerts = [];
            foreach ($machine_daily as $mname => $daily) {
                ksort($daily);
                $dates = array_keys($daily);
                foreach ($dates as $idx => $d) {
                    if ($d < $df || $d > $dt) continue;
                    $window = array_slice(array_values($daily), max(0,$idx-7), min($idx,7));
                    if (count($window) < 3) continue;
                    $avg = array_sum($window) / count($window);
                    $cur = $daily[$d];
                    if ($avg > 0 && $cur < $avg * 0.70) {
                        $machine_alerts[$d][] = [
                            'machine'  => $mname,
                            'current'  => $cur,
                            'average'  => round($avg),
                            'drop_pct' => round((1 - $cur/$avg)*100),
                        ];
                    }
                }
            }

            $days = [];
            $cur = new DateTime(sprintf('%04d-%02d-01', $year, $month));
            $end = new DateTime(date('Y-m-t', strtotime($cur->format('Y-m-d'))));
            while ($cur <= $end) {
                $d = $cur->format('Y-m-d');
                $days[$d] = [
                    'fp'         => (int)($fp_data[$d]['fp_total']   ?? 0),
                    'bp'         => (int)($bp_data[$d]['bp_total']   ?? 0),
                    'deno'       => (int)($dn_data[$d]['deno_total'] ?? 0),
                    'd2m'        => (int)($d2_data[$d]['d2m_total']  ?? 0),
                    'jt'         => (int)($fp_data[$d]['jt_count']   ?? 0),
                    'book_codes' => $fp_data[$d]['book_codes']        ?? '',
                    'alerts'     => $machine_alerts[$d] ?? [],
                ];
                $cur->modify('+1 day');
            }

            $summary = [
                'fp_total'   => (int)array_sum(array_column($fp_data,'fp_total')),
                'bp_total'   => (int)array_sum(array_column($bp_data,'bp_total')),
                'deno_total' => (int)array_sum(array_column($dn_data,'deno_total')),
                'd2m_total'  => (int)array_sum(array_column($d2_data,'d2m_total')),
                'jt_total'   => (int)array_sum(array_column($fp_data,'jt_count')),
            ];

            echo json_encode(['success'=>true,'days'=>$days,'summary'=>$summary]);
        } catch (Throwable $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit();
    }

    // ════════════════════════════════════════════════════════════════
    // AJAX: day_detail — daily breakdown
    //   FP rows now show JT, total qty, book code, machine, operator,
    //   supervisor, incharge — per spec
    // ════════════════════════════════════════════════════════════════
    if ($_GET['ajax'] === 'day_detail') {
        $date      = $_GET['date']              ?? '';
        $fy_id     = (int)($_GET['fiscal_year'] ?? 0);
        $book_code = trim($_GET['book_code']    ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            echo json_encode(['success'=>false,'message'=>'Invalid date']); exit();
        }

        try {
            // ── FP rows ─────────────────────────────────────────────
            $fp_w = ['fp.created_date::date = :date', 'fp.status = true'];
            $fp_p = [':date' => $date];
            if ($fy_id)     { $fp_w[] = 'fp.fiscal_year_id = :fy'; $fp_p[':fy'] = $fy_id; }
            if ($book_code) { $fp_w[] = 'b.book_code = :bc';       $fp_p[':bc'] = $book_code; }

            $st = $conn->prepare("
                SELECT fp.id                            AS fp_id,
                       fp.jt_id,
                       jt.job_ticket_code,
                       b.book_code,
                       b.book_name,
                       jt.class                         AS class_level,
                       jt.print_qty                     AS jt_target_qty,
                       fp.fp_printqty                   AS fp_qty,
                       fp.jtd_id,
                       u_op.username                    AS operator_name,
                       u_sv.username                    AS supervisor_name,
                       u_ic.username                    AS incharge_name,
                       m.machine_name,
                       s.name                           AS shift_name,
                       fp.date_nep,
                       fp.date_eng,
                       fp.status,
                       fp.created_date                  AS entry_time
                FROM forma_printing fp
                JOIN  job_ticket  jt   ON jt.id       = fp.jt_id
                JOIN  books       b    ON b.book_id   = jt.book_id
                LEFT JOIN users   u_op ON u_op.id     = fp.operator_id
                LEFT JOIN users   u_sv ON u_sv.id     = fp.supervisor_id
                LEFT JOIN users   u_ic ON u_ic.id     = fp.incharge_id
                LEFT JOIN machines m   ON m.id        = fp.machine_id
                LEFT JOIN shifts   s   ON s.id        = fp.shift_id
                WHERE " . implode(' AND ', $fp_w) . "
                ORDER BY fp.fp_printqty DESC, fp.created_date DESC
            ");
            foreach ($fp_p as $k => $v) $st->bindValue($k, $v);
            $st->execute();
            $fp_rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // ── BP rows ─────────────────────────────────────────────
            $bp_w = ['bp.created_date::date = :date', 'bp.status = true'];
            $bp_p = [':date' => $date];
            if ($fy_id)     { $bp_w[] = 'bp.fiscal_year_id = :fy'; $bp_p[':fy'] = $fy_id; }
            if ($book_code) { $bp_w[] = 'bp.book_code = :bc';      $bp_p[':bc'] = $book_code; }

            $st2 = $conn->prepare("
                SELECT bp.id                         AS bp_id,
                       bp.jt_id,
                       jt.job_ticket_code,
                       bp.book_code,
                       jt.print_qty                  AS jt_target_qty,
                       bp.p_qty                      AS bp_qty,
                       u_op.username                 AS operator_name,
                       u_sv.username                 AS supervisor_name,
                       u_ic.username                 AS incharge_name,
                       bp.date_nep,
                       bp.date_eng,
                       bp.packing_status,
                       bp.created_date               AS entry_time
                FROM book_packing bp
                JOIN  job_ticket jt   ON jt.id       = bp.jt_id
                LEFT JOIN users  u_op ON u_op.id     = bp.operator_id
                LEFT JOIN users  u_sv ON u_sv.id     = bp.supervisor_id
                LEFT JOIN users  u_ic ON u_ic.id     = bp.incharge_id
                WHERE " . implode(' AND ', $bp_w) . "
                ORDER BY bp.p_qty DESC
            ");
            foreach ($bp_p as $k => $v) $st2->bindValue($k, $v);
            $st2->execute();
            $bp_rows = $st2->fetchAll(PDO::FETCH_ASSOC);

            // ── Deno ────────────────────────────────────────────────
            $deno_date_dot = date('Y.m.d', strtotime($date));
            $dn_w = ['d.deno_date_eng = :ddate', 'd.deleted_at IS NULL'];
            $dn_p = [':ddate' => $deno_date_dot];
            if ($book_code) { $dn_w[] = 'd.book_code = :bc'; $dn_p[':bc'] = $book_code; }
            $st3 = $conn->prepare("
                SELECT d.book_code, d.ref_no, d.deno_date_nep, d.deno_date_eng,
                       COALESCE(SUM(d.total_qty),0) AS deno_qty
                FROM deno d
                WHERE " . implode(' AND ', $dn_w) . "
                GROUP BY d.book_code, d.ref_no, d.deno_date_nep, d.deno_date_eng
            ");
            foreach ($dn_p as $k => $v) $st3->bindValue($k, $v);
            $st3->execute();
            $deno_rows = $st3->fetchAll(PDO::FETCH_ASSOC);

            // ── D2M ─────────────────────────────────────────────────
            $d2_w = ['dm.eng_date::date = :date', 'dm.deleted_at IS NULL'];
            $d2_p = [':date' => $date];
            if ($book_code) { $d2_w[] = 'di.book_code = :bc'; $d2_p[':bc'] = $book_code; }
            $st4 = $conn->prepare("
                SELECT di.book_code, dm.d2m_no, dm.status, dm.eng_date,
                       COALESCE(SUM(di.total_qty),0) AS d2m_qty
                FROM d2m_items di
                JOIN d2m dm ON dm.id = di.d2m_id
                WHERE " . implode(' AND ', $d2_w) . "
                GROUP BY di.book_code, dm.d2m_no, dm.status, dm.eng_date
            ");
            foreach ($d2_p as $k => $v) $st4->bindValue($k, $v);
            $st4->execute();
            $d2m_rows = $st4->fetchAll(PDO::FETCH_ASSOC);

            // ── Machine summary ─────────────────────────────────────
            $mc_w = ['fp.created_date::date = :date', 'fp.status = true'];
            $mc_p = [':date' => $date];
            if ($fy_id) { $mc_w[] = 'fp.fiscal_year_id = :fy'; $mc_p[':fy'] = $fy_id; }
            $st5 = $conn->prepare("
                SELECT m.machine_name,
                       u_op.username                    AS operator_name,
                       u_sv.username                    AS supervisor_name,
                       u_ic.username                    AS incharge_name,
                       s.name                           AS shift_name,
                       COALESCE(SUM(fp.fp_printqty),0)  AS total_qty,
                       COUNT(fp.id)                     AS entry_count
                FROM forma_printing fp
                LEFT JOIN machines m   ON m.id       = fp.machine_id
                LEFT JOIN users u_op   ON u_op.id    = fp.operator_id
                LEFT JOIN users u_sv   ON u_sv.id    = fp.supervisor_id
                LEFT JOIN users u_ic   ON u_ic.id    = fp.incharge_id
                LEFT JOIN shifts s     ON s.id       = fp.shift_id
                WHERE " . implode(' AND ', $mc_w) . "
                GROUP BY m.machine_name, u_op.username, u_sv.username, u_ic.username, s.name
                ORDER BY total_qty DESC
            ");
            foreach ($mc_p as $k => $v) $st5->bindValue($k, $v);
            $st5->execute();
            $machine_summary = $st5->fetchAll(PDO::FETCH_ASSOC);

            // ── 14-day trend ─────────────────────────────────────────
            $trend_from = date('Y-m-d', strtotime($date . ' -14 days'));
            $st6 = $conn->prepare("
                SELECT fp.created_date::date AS day,
                       m.machine_name,
                       COALESCE(SUM(fp.fp_printqty),0) AS qty
                FROM forma_printing fp
                LEFT JOIN machines m ON m.id = fp.machine_id
                WHERE fp.created_date::date BETWEEN :ts AND :te
                  AND fp.status = true
                GROUP BY fp.created_date::date, m.machine_name
                ORDER BY fp.created_date::date, m.machine_name
            ");
            $st6->bindValue(':ts', $trend_from);
            $st6->bindValue(':te', $date);
            $st6->execute();
            $trend_raw = $st6->fetchAll(PDO::FETCH_ASSOC);

            $machine_trend = [];
            foreach ($trend_raw as $row) {
                $mn = $row['machine_name'] ?: 'Unknown';
                $machine_trend[$mn][$row['day']] = (int)$row['qty'];
            }

            $trend_alerts = [];
            foreach ($machine_trend as $mname => $daily) {
                if (!isset($daily[$date])) continue;
                ksort($daily);
                $dates = array_keys($daily);
                $idx = array_search($date, $dates);
                if ($idx === false || $idx < 2) continue;
                $window = array_slice(array_values($daily), max(0,$idx-7), min($idx,7));
                if (count($window) < 2) continue;
                $avg = array_sum($window) / count($window);
                $cur = $daily[$date];
                if ($avg > 0 && $cur < $avg * 0.70) {
                    $trend_alerts[] = [
                        'machine'  => $mname,
                        'current'  => $cur,
                        'average'  => round($avg),
                        'drop_pct' => round((1 - $cur/$avg)*100),
                        'trend'    => array_values($daily),
                        'dates'    => $dates,
                    ];
                }
            }

            // ── BP totals per JT (for Book Qty in FP table) ─────────
            $jt_ids_in_day = array_unique(array_column($fp_rows, 'jt_id'));
            $bp_by_jt = [];
            if (!empty($jt_ids_in_day)) {
                $ph = implode(',', array_map(fn($i)=>'?', $jt_ids_in_day));
                $stx = $conn->prepare("
                    SELECT jt_id, COALESCE(SUM(p_qty),0) AS total_packed
                    FROM book_packing
                    WHERE jt_id IN ($ph) AND status = true
                    GROUP BY jt_id
                ");
                $stx->execute(array_values($jt_ids_in_day));
                foreach ($stx->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $bp_by_jt[$r['jt_id']] = (int)$r['total_packed'];
                }
            }

            echo json_encode([
                'success'         => true,
                'date'            => $date,
                'fp_rows'         => $fp_rows,
                'bp_rows'         => $bp_rows,
                'deno_rows'       => $deno_rows,
                'd2m_rows'        => $d2m_rows,
                'machine_summary' => $machine_summary,
                'trend_alerts'    => $trend_alerts,
                'machine_trend'   => $machine_trend,
                'bp_by_jt'        => $bp_by_jt,
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit();
    }

    // ════════════════════════════════════════════════════════════════
    // AJAX: trend_analysis — time-bucketed output for charts
    //   Group buckets: day | month | quarter | half | year
    //   Quarters follow BS fiscal calendar (Q1=Shrawan-Ashwin, etc.)
    //   Returns raw daily data; bucket grouping done in JS using BS dates
    // ════════════════════════════════════════════════════════════════
    if ($_GET['ajax'] === 'trend_analysis') {
        $fy_id     = (int)($_GET['fiscal_year'] ?? 0);
        $book_code = trim($_GET['book_code']    ?? '');
        $date_from = trim($_GET['date_from']    ?? '');
        $date_to   = trim($_GET['date_to']      ?? '');

        // Default: last 365 days
        $dt = $date_to ?: date('Y-m-d');
        $df = $date_from ?: date('Y-m-d', strtotime($dt . ' -365 days'));

        try {
            // Per-day per-machine FP qty
            $w = ['fp.created_date::date BETWEEN :df AND :dt', 'fp.status = true'];
            $p = [':df' => $df, ':dt' => $dt];
            if ($fy_id)     { $w[] = 'fp.fiscal_year_id = :fy'; $p[':fy'] = $fy_id; }
            if ($book_code) { $w[] = 'b.book_code = :bc';       $p[':bc'] = $book_code; }

            $st = $conn->prepare("
                SELECT fp.created_date::date AS day,
                       COALESCE(m.machine_name,'Unknown') AS machine_name,
                       COALESCE(SUM(fp.fp_printqty),0)   AS fp_qty
                FROM forma_printing fp
                JOIN job_ticket jt ON jt.id    = fp.jt_id
                JOIN books      b  ON b.book_id = jt.book_id
                LEFT JOIN machines m ON m.id   = fp.machine_id
                WHERE " . implode(' AND ', $w) . "
                GROUP BY fp.created_date::date, m.machine_name
                ORDER BY fp.created_date::date ASC
            ");
            foreach ($p as $k => $v) $st->bindValue($k, $v);
            $st->execute();
            $fp_rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // BP per day (single series)
            $w2 = ['bp.created_date::date BETWEEN :df AND :dt', 'bp.status = true'];
            $p2 = [':df' => $df, ':dt' => $dt];
            if ($fy_id)     { $w2[] = 'bp.fiscal_year_id = :fy'; $p2[':fy'] = $fy_id; }
            if ($book_code) { $w2[] = 'bp.book_code = :bc';      $p2[':bc'] = $book_code; }

            $st2 = $conn->prepare("
                SELECT bp.created_date::date AS day,
                       COALESCE(SUM(bp.p_qty),0) AS bp_qty
                FROM book_packing bp
                WHERE " . implode(' AND ', $w2) . "
                GROUP BY bp.created_date::date
                ORDER BY bp.created_date::date ASC
            ");
            foreach ($p2 as $k => $v) $st2->bindValue($k, $v);
            $st2->execute();
            $bp_rows = $st2->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'date_from' => $df,
                'date_to'   => $dt,
                'fp_rows'   => $fp_rows,
                'bp_rows'   => $bp_rows,
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit();
    }

    // ════════════════════════════════════════════════════════════════
    // AJAX: manpower — per-shift hourly outcome with detailed records
    // ════════════════════════════════════════════════════════════════
    if ($_GET['ajax'] === 'manpower') {
        $fy_id     = (int)($_GET['fiscal_year'] ?? 0);
        $book_code = trim($_GET['book_code']    ?? '');
        $date_from = trim($_GET['date_from']    ?? '');
        $date_to   = trim($_GET['date_to']      ?? '');
        $dt = $date_to ?: date('Y-m-d');
        $df = $date_from ?: date('Y-m-d', strtotime($dt . ' -30 days'));

        try {
            $w = ['fp.created_date::date BETWEEN :df AND :dt', 'fp.status = true'];
            $p = [':df' => $df, ':dt' => $dt];
            if ($fy_id)     { $w[] = 'fp.fiscal_year_id = :fy'; $p[':fy'] = $fy_id; }
            if ($book_code) { $w[] = 'b.book_code = :bc';       $p[':bc'] = $book_code; }

            // Detailed records: each FP entry with full context
            $st = $conn->prepare("
                SELECT fp.id                          AS fp_id,
                       fp.created_date::date          AS work_date,
                       fp.date_nep                    AS work_date_nep,
                       jt.job_ticket_code,
                       b.book_code,
                       fp.fp_printqty                 AS qty,
                       COALESCE(m.machine_name,'—')   AS machine_name,
                       COALESCE(s.name,'—')           AS shift_name,
                       COALESCE(u_op.username,'—')    AS operator_name,
                       COALESCE(u_sv.username,'—')    AS supervisor_name,
                       COALESCE(u_ic.username,'—')    AS incharge_name
                FROM forma_printing fp
                JOIN job_ticket jt ON jt.id       = fp.jt_id
                JOIN books      b  ON b.book_id   = jt.book_id
                LEFT JOIN machines m  ON m.id     = fp.machine_id
                LEFT JOIN shifts   s  ON s.id     = fp.shift_id
                LEFT JOIN users u_op  ON u_op.id  = fp.operator_id
                LEFT JOIN users u_sv  ON u_sv.id  = fp.supervisor_id
                LEFT JOIN users u_ic  ON u_ic.id  = fp.incharge_id
                WHERE " . implode(' AND ', $w) . "
                ORDER BY fp.created_date DESC
                LIMIT 500
            ");
            foreach ($p as $k => $v) $st->bindValue($k, $v);
            $st->execute();
            $records = $st->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'  => true,
                'date_from'=> $df,
                'date_to'  => $dt,
                'records'  => $records,
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit();
    }
    exit();
}

// ═══════════════════════════════════════════════════════════════════════════
// PAGE RENDER
// ═══════════════════════════════════════════════════════════════════════════
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

if (!has_role('viewer') && !has_role('operator') && !has_role('incharge') &&
    !has_role('supervisor') && !has_role('admin') && !has_role('press') && !has_role('marketing')) {
    ob_end_clean(); header('Location: /deno2/unauthorized.php'); exit();
}

$fiscal_years   = $conn->query("SELECT id, fiscal_code, fiscal_name FROM fiscal_years ORDER BY fiscal_code DESC")->fetchAll(PDO::FETCH_ASSOC);
$active_fy      = $conn->query("SELECT id FROM fiscal_years WHERE is_active = true LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$books_dropdown = $conn->query("SELECT DISTINCT book_code, book_name FROM books ORDER BY book_code ASC")->fetchAll(PDO::FETCH_ASSOC);

$fiscal_year_filter = $_GET['fiscal_year'] ?? ($active_fy['id'] ?? '');
$book_code_filter   = $_GET['book_code']   ?? '';
$cal_year           = (int)($_GET['cal_year']  ?? date('Y'));
$cal_month          = (int)($_GET['cal_month'] ?? date('n'));
$cal_month          = max(1, min(12, $cal_month));
$self_url           = strtok($_SERVER['REQUEST_URI'], '?');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Calendar Production Report</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
<style>
:root{
  --bg:#fff;--surface:#f8fafc;--surface2:#f1f5f9;--border:#e2e8f0;
  --accent:#4f46e5;--text:#1e293b;--text-muted:#64748b;
  --success:#10b981;--warning:#f59e0b;--danger:#ef4444;--radius:10px;
  --fp:#3b82f6;--bp:#10b981;--dn:#f59e0b;--d2m:#8b5cf6;
  --today-bg:#fffbeb;--today-bdr:#f59e0b;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden;font-family:'Segoe UI',system-ui,sans-serif;
          font-size:13px;background:var(--bg);color:var(--text)}
body{display:flex;flex-direction:column}

/* Top bar */
#topBar{flex-shrink:0;display:flex;align-items:center;gap:8px;padding:0 12px;
        height:44px;background:var(--surface);border-bottom:1px solid var(--border)}
#topBar .page-title{font-size:14px;font-weight:800;margin-right:auto}
.legend{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.li{display:flex;align-items:center;gap:3px;font-size:10px;font-weight:600;color:var(--text-muted)}
.ld{width:8px;height:8px;border-radius:50%}

/* Summary strip */
#sumStrip{flex-shrink:0;display:flex;background:var(--border);gap:2px;height:58px}
.sc{flex:1;background:var(--bg);display:flex;flex-direction:column;justify-content:center;
    padding:0 12px;position:relative;overflow:hidden}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--ac,var(--accent))}
.sc .sv{font-size:16px;font-weight:800}
.sc .sl{font-size:9px;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-top:1px}

/* View tabs (Calendar | Trends | Manpower) */
#viewTabs{flex-shrink:0;display:flex;background:var(--bg);border-bottom:2px solid var(--border)}
.vtab{padding:8px 18px;font-size:12px;font-weight:700;border:none;background:none;
      color:var(--text-muted);cursor:pointer;border-bottom:3px solid transparent;
      margin-bottom:-2px;transition:.15s}
.vtab.active{color:var(--accent);border-bottom-color:var(--accent)}
.vtab:hover{color:var(--accent)}

/* Filter bar */
#filterBar{flex-shrink:0;display:flex;align-items:center;gap:5px;flex-wrap:wrap;
           padding:4px 12px;background:var(--surface);border-bottom:1px solid var(--border)}
.fc{background:var(--bg);border:1px solid var(--border);border-radius:6px;
    color:var(--text);padding:3px 7px;font-size:11px;outline:none}
.fc:focus{border-color:var(--accent)}
.fc-label{font-size:9px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px}
.fc-sep{width:1px;height:20px;background:var(--border);margin:0 2px}
.btn-f{display:inline-flex;align-items:center;gap:3px;padding:4px 11px;border:none;
       border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;transition:.12s}
.btn-f:hover{opacity:.88;transform:translateY(-1px)}
.btn-pri{background:var(--accent);color:#fff}
.btn-sec{background:var(--surface2);color:var(--text);border:1px solid var(--border)}
.btn-grn{background:var(--success);color:#fff}

/* View containers */
.view-container{flex:1;display:none;flex-direction:column;overflow:hidden;position:relative}
.view-container.active{display:flex}

/* Calendar nav */
#calNav{flex-shrink:0;display:flex;align-items:center;gap:7px;
        padding:4px 12px;background:var(--bg);border-bottom:1px solid var(--border)}
#calNav .cal-title{font-size:15px;font-weight:800}
#calNav .nep-badge{background:#f0fdf4;border:1px solid #86efac;border-radius:20px;
                   padding:2px 10px;font-size:11px;font-weight:700;color:#166534}
.nav-btn{background:var(--surface2);border:1px solid var(--border);border-radius:6px;
         padding:3px 10px;font-size:12px;font-weight:700;cursor:pointer;color:var(--text);transition:.12s}
.nav-btn:hover,.nav-btn.active-nb{background:var(--accent);color:#fff;border-color:var(--accent)}

/* Calendar */
#wkRow{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;
       background:var(--border);flex-shrink:0;height:24px}
.wk-cell{background:var(--surface2);display:flex;align-items:center;justify-content:center;
         font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px}
.wk-cell.sat{color:var(--fp)}.wk-cell.sun{color:var(--danger)}
#dayGrid{flex:1;display:grid;grid-template-columns:repeat(7,1fr);
         gap:2px;background:var(--border);overflow:hidden;min-height:0}

/* Day cell */
.dc{background:var(--bg);overflow:hidden;display:flex;flex-direction:column;
    cursor:pointer;transition:background .12s;position:relative;min-height:0}
.dc:hover{background:rgba(79,70,229,.05)}
.dc.is-today{background:var(--today-bg);outline:2px solid var(--today-bdr);outline-offset:-2px}
.dc.is-sat{background:#f0f7ff}.dc.is-sun{background:#fff5f5}
.dc.other-mo{background:var(--surface);opacity:.32;cursor:default;pointer-events:none}
.dc.has-data{border-top:2px solid var(--accent)}
.dc.has-alert{border-top:3px solid var(--danger)!important}
.dc.in-range{background:#eef2ff!important}
.dc.range-start,.dc.range-end{background:#c7d2fe!important}

.dc-head{display:flex;justify-content:space-between;align-items:flex-start;
         padding:3px 5px 1px;flex-shrink:0;border-bottom:1px solid var(--border);background:inherit}
.dc-eng{font-size:13px;font-weight:800;line-height:1}
.dc-nep-wrap{display:flex;flex-direction:column;align-items:flex-end;line-height:1.2}
.dc-nep-mo{font-size:7px;font-weight:700;color:#166534;opacity:.9}
.dc-nep-day{font-size:11px;font-weight:800;color:#166534}
.dc.is-today .dc-eng{color:var(--accent)}
.dc.is-sat .dc-eng{color:var(--fp)}.dc.is-sun .dc-eng{color:var(--danger)}
.alert-flag{position:absolute;top:2px;right:3px;font-size:10px;
            animation:pf 1.8s ease-in-out infinite;cursor:default}
@keyframes pf{0%,100%{transform:scale(1)}50%{transform:scale(1.35);opacity:.7}}

.dc-books{padding:0 5px;font-size:7.5px;font-weight:700;color:var(--accent);
          white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
          line-height:1.5;border-bottom:1px solid var(--border);flex-shrink:0}
.dc-metrics{flex:1;display:flex;flex-direction:column;justify-content:space-evenly;
            padding:1px 5px;min-height:0}
.dc-row{display:flex;align-items:center;gap:2px;font-size:10px;font-weight:700;
        line-height:1;white-space:nowrap;overflow:hidden}
.dc-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.dc-lbl{color:var(--text-muted);font-weight:600;min-width:22px;font-size:9px}
.dc-val{font-size:10px;font-weight:800;color:var(--text)}
.dc-val.zero{color:#cbd5e1;font-weight:400}
.dc-foot{flex-shrink:0;font-size:8px;font-weight:600;color:var(--text-muted);
         padding:1px 5px 2px;border-top:1px solid var(--border);
         display:flex;align-items:center;gap:3px}

/* Overlay */
.overlay{position:absolute;inset:0;background:rgba(255,255,255,.75);display:none;
         align-items:center;justify-content:center;z-index:20;backdrop-filter:blur(2px)}
.overlay.show{display:flex}
.spinner{width:32px;height:32px;border:3px solid var(--border);border-top-color:var(--accent);
         border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* Modal */
.modal-content{background:var(--bg);border:1px solid var(--border);border-radius:var(--radius)}
.modal-header{border-bottom:1px solid var(--border);padding:11px 15px}
.modal-body{padding:13px;max-height:78vh;overflow-y:auto}
.modal-footer{border-top:1px solid var(--border);padding:8px 15px}
.ds{margin-bottom:14px}
.ds-title{font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;
          letter-spacing:.5px;margin-bottom:6px;display:flex;align-items:center;gap:6px}
.ds-title::after{content:'';flex:1;height:1px;background:var(--border)}
.dt{width:100%;border-collapse:collapse;font-size:11px}
.dt th{background:var(--surface2);padding:5px 7px;text-align:left;font-size:9px;font-weight:700;
       text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);
       border-bottom:1px solid var(--border);position:sticky;top:0;z-index:1}
.dt td{padding:5px 7px;border-bottom:1px solid var(--border)}
.dt tr:last-child td{border-bottom:none}
.dt tr:hover td{background:var(--surface)}
.dt .r{text-align:right;font-weight:700}
.sb{display:inline-block;padding:1px 6px;border-radius:20px;font-size:9px;font-weight:700;text-transform:uppercase}
.sb-a{background:#d1fae5;color:#065f46}.sb-p{background:#fef3c7;color:#92400e}
.sb-d{background:#e0e7ff;color:#3730a3}.sb-c{background:#fee2e2;color:#991b1b}
.tag{display:inline-block;padding:1px 5px;border-radius:4px;font-size:10px;font-weight:700}
.tag-fp{background:#dbeafe;color:#1d4ed8}.tag-bp{background:#d1fae5;color:#065f46}
.tag-dn{background:#fef3c7;color:#92400e}.tag-d2m{background:#ede9fe;color:#5b21b6}
.tag-jt{background:#fce7f3;color:#9d174d}

/* Tabs in modal */
.tab-bar{display:flex;border-bottom:2px solid var(--border);margin-bottom:12px;flex-wrap:wrap}
.tab-btn{padding:5px 13px;font-size:11px;font-weight:700;border:none;background:none;
         color:var(--text-muted);cursor:pointer;border-bottom:2px solid transparent;
         margin-bottom:-2px;transition:.15s}
.tab-btn.active{color:var(--accent);border-bottom-color:var(--accent)}
.tab-pane{display:none}.tab-pane.active{display:block}

/* Alert banner */
.alert-banner{background:linear-gradient(135deg,#fff1f1,#fff7f0);border:2px solid var(--danger);
              border-radius:10px;padding:12px 14px;margin-bottom:13px}
.alert-banner-title{font-size:12px;font-weight:800;color:var(--danger);
                    display:flex;align-items:center;gap:7px;margin-bottom:9px}
.alert-item{display:flex;align-items:center;gap:9px;flex-wrap:wrap;
            background:#fff;border:1px solid #fca5a5;border-radius:7px;
            padding:8px 11px;margin-bottom:6px}
.alert-item:last-child{margin-bottom:0}
.alert-machine-name{font-weight:800;color:var(--danger);min-width:100px;font-size:12px}
.alert-drop-badge{background:var(--danger);color:#fff;font-size:10px;
                  font-weight:800;padding:2px 7px;border-radius:20px}
.machine-alert-row{background:#fff1f1!important}
.machine-alert-row td{color:var(--danger)!important;font-weight:700!important}

/* Sparkline */
.spk-wrap{display:inline-flex;align-items:flex-end;gap:1px;height:26px;vertical-align:middle}
.spkb{width:5px;border-radius:2px;min-height:2px}

/* Trend chart wrapper */
.trend-wrap{background:var(--surface);border-radius:7px;padding:11px;margin-bottom:11px}
.trend-title{font-size:10px;font-weight:700;color:var(--text-muted);margin-bottom:5px;
             display:flex;align-items:center;gap:7px}

/* ── Trend / Manpower view body ──────────────────────────────────── */
.view-body{flex:1;overflow-y:auto;padding:14px 18px;background:var(--surface)}
.panel{background:var(--bg);border:1px solid var(--border);border-radius:10px;
       padding:15px 18px;margin-bottom:14px}
.panel-title{font-size:14px;font-weight:800;margin-bottom:12px;display:flex;
             align-items:center;gap:8px;color:var(--text)}
.panel-sub{font-size:11px;color:var(--text-muted);margin-bottom:10px}

/* Bucket selector */
.bucket-bar{display:flex;gap:4px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
.bucket-btn{padding:5px 13px;font-size:11px;font-weight:700;border:1px solid var(--border);
            border-radius:6px;background:var(--bg);color:var(--text-muted);cursor:pointer;transition:.12s}
.bucket-btn.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.bucket-btn:hover{border-color:var(--accent)}

/* Chart canvas wrapper */
.chart-wrap{position:relative;width:100%;height:340px}
.chart-wrap canvas{max-width:100%}

/* Manpower stats */
.mp-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:14px}
.mp-stat{background:var(--surface);border-radius:8px;padding:10px 12px;border-left:3px solid var(--accent)}
.mp-stat-v{font-size:18px;font-weight:800}
.mp-stat-l{font-size:10px;color:var(--text-muted);text-transform:uppercase;font-weight:700;letter-spacing:.4px}

/* Debug panel */
#debugPanel{display:none;position:fixed;bottom:0;right:0;width:440px;max-height:260px;
            background:#0f172a;color:#94a3b8;font-size:10px;font-family:monospace;
            padding:10px;overflow-y:auto;z-index:9999;border-top:2px solid var(--accent);
            border-left:2px solid var(--accent);border-radius:10px 0 0 0}
#debugPanel.show{display:block}
#debugToggle{position:fixed;bottom:8px;right:8px;z-index:10000;background:#0f172a;
             color:#94a3b8;border:1px solid #334155;border-radius:6px;padding:4px 9px;
             font-size:10px;cursor:pointer;font-family:monospace}

@media(max-width:900px){.dc-val{font-size:9px}.dc-eng{font-size:12px}}
@media(max-width:640px){.dc-nep-wrap,.dc-lbl,.dc-foot,.dc-books{display:none}
  .dc-eng{font-size:11px}#filterBar .fc-label{display:none}}
</style>
</head>
<body>

<!-- TOP BAR -->
<div id="topBar">
  <div class="page-title">📅 Calendar Production Report</div>
  <div class="legend">
    <span class="li"><span class="ld" style="background:var(--fp)"></span>FP</span>
    <span class="li"><span class="ld" style="background:var(--bp)"></span>BP</span>
    <span class="li"><span class="ld" style="background:var(--dn)"></span>Deno</span>
    <span class="li"><span class="ld" style="background:var(--d2m)"></span>D2M</span>
    <span class="li"><span class="ld" style="background:var(--danger)"></span>⚠ Alert</span>
  </div>
  <a href="../index.php" class="btn-f btn-sec">← Dashboard</a>
  <button class="btn-f btn-pri" id="exportMonthBtn">⬇ Export</button>
</div>

<!-- SUMMARY STRIP -->
<div id="sumStrip">
  <?php foreach([
    ['s-fp',  '🖨 Forma Printed','#3b82f6'],
    ['s-bp',  '📦 Book Packed (final qty)',  '#10b981'],
    ['s-dn',  '🚚 Deno Qty',     '#f59e0b'],
    ['s-d2m', '🏭 D2M Qty',      '#8b5cf6'],
    ['s-jt',  '📋 Job Tickets',  '#6366f1'],
  ] as [$id,$lbl,$ac]): ?>
  <div class="sc" style="--ac:<?= $ac ?>">
    <div class="sv" id="<?= $id ?>">—</div>
    <div class="sl"><?= $lbl ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- VIEW TABS -->
<div id="viewTabs">
  <button class="vtab active" data-view="view-calendar">📅 Calendar</button>
  <button class="vtab" data-view="view-trends">📈 Trend Analysis</button>
  <button class="vtab" data-view="view-manpower">👥 Manpower</button>
</div>

<!-- FILTER BAR -->
<div id="filterBar">
  <span class="fc-label">FY</span>
  <select id="filterFY" class="fc">
    <option value="">All</option>
    <?php foreach($fiscal_years as $fy): ?>
    <option value="<?= $fy['id'] ?>" <?= ($fiscal_year_filter==$fy['id'])?'selected':'' ?>>
      <?= htmlspecialchars($fy['fiscal_code'].' – '.$fy['fiscal_name']) ?>
    </option>
    <?php endforeach; ?>
  </select>
  <span class="fc-label">Book</span>
  <select id="filterBook" class="fc">
    <option value="">All Books</option>
    <?php foreach($books_dropdown as $bk): ?>
    <option value="<?= htmlspecialchars($bk['book_code']) ?>" <?= ($book_code_filter===$bk['book_code'])?'selected':'' ?>>
      <?= htmlspecialchars($bk['book_code'].' – '.$bk['book_name']) ?>
    </option>
    <?php endforeach; ?>
  </select>
  <div class="fc-sep"></div>
  <span class="fc-label">From (AD)</span><input type="date" id="dateFromEng" class="fc">
  <span class="fc-label">To (AD)</span><input type="date" id="dateToEng" class="fc">
  <div class="fc-sep"></div>
  <span class="fc-label">From (BS)</span>
  <input type="text" id="dateFromNep" class="fc" placeholder="YYYY-MM-DD" style="width:105px" autocomplete="off">
  <span class="fc-label">To (BS)</span>
  <input type="text" id="dateToNep" class="fc" placeholder="YYYY-MM-DD" style="width:105px" autocomplete="off">
  <div class="fc-sep"></div>
  <span class="fc-label">Month</span>
  <input type="month" id="jumpMonth" class="fc" value="<?= sprintf('%04d-%02d',$cal_year,$cal_month) ?>">
  <div class="fc-sep"></div>
  <button class="btn-f btn-pri" id="applyBtn">🔍 Apply</button>
  <button class="btn-f btn-sec" id="resetBtn">↺ Reset</button>
</div>

<!-- VIEW: CALENDAR -->
<div class="view-container active" id="view-calendar">
  <div id="calNav">
    <button class="nav-btn" id="prevBtn">‹ Prev</button>
    <button class="nav-btn active-nb" id="todayBtn">Today</button>
    <button class="nav-btn" id="nextBtn">Next ›</button>
    <span class="cal-title" id="calTitle">—</span>
    <span class="nep-badge" id="nepBadge">BS —</span>
    <div style="margin-left:auto;display:flex;align-items:center;gap:8px">
      <span id="rangeInfo" style="font-size:11px;color:var(--text-muted)"></span>
      <span id="alertBadge" style="display:none;font-size:10px;font-weight:800;color:var(--danger);
            background:#fee2e2;padding:2px 8px;border-radius:20px"></span>
    </div>
  </div>
  <div class="overlay" id="calOverlay"><div class="spinner"></div></div>
  <div id="wkRow">
    <div class="wk-cell sun">Sun</div><div class="wk-cell">Mon</div><div class="wk-cell">Tue</div>
    <div class="wk-cell">Wed</div><div class="wk-cell">Thu</div><div class="wk-cell">Fri</div>
    <div class="wk-cell sat">Sat</div>
  </div>
  <div id="dayGrid"></div>
</div>

<!-- VIEW: TRENDS -->
<div class="view-container" id="view-trends">
  <div class="overlay" id="trendOverlay"><div class="spinner"></div></div>
  <div class="view-body">
    <div class="panel">
      <div class="panel-title">📈 Output Comparison (Nepali Calendar)</div>
      <p class="panel-sub" id="trendSub">Loading…</p>
      <div class="bucket-bar">
        <span style="font-size:10px;font-weight:700;color:var(--text-muted);margin-right:4px">GROUP BY:</span>
        <button class="bucket-btn" data-bucket="day">Day</button>
        <button class="bucket-btn active" data-bucket="month">Month (BS)</button>
        <button class="bucket-btn" data-bucket="quarter">Quarter (BS Fiscal)</button>
        <button class="bucket-btn" data-bucket="half">Half-Year (BS)</button>
        <button class="bucket-btn" data-bucket="year">Year (BS)</button>
      </div>
      <div class="chart-wrap"><canvas id="trendChart"></canvas></div>
    </div>

    <div class="panel">
      <div class="panel-title">📦 Book Packing Output Comparison (FP vs BP)</div>
      <p class="panel-sub">
        <strong>Note:</strong> FP qty is the intermediate forma sheets (e.g. 7 formas × 100K each).
        BP qty is the actual books packed. Never sum FP across formas as "books made".
      </p>
      <div class="chart-wrap"><canvas id="fpBpChart"></canvas></div>
    </div>
  </div>
</div>

<!-- VIEW: MANPOWER -->
<div class="view-container" id="view-manpower">
  <div class="overlay" id="manpowerOverlay"><div class="spinner"></div></div>
  <div class="view-body">
    <div class="panel">
      <div class="panel-title">⏱ Per-Shift Hourly Outcome</div>
      <p class="panel-sub">Aggregated by shift across the selected date range (BS &amp; AD shown).</p>
      <div id="shiftSummary"></div>
    </div>

    <div class="panel">
      <div class="panel-title">📋 Manpower Detail Log</div>
      <p class="panel-sub">Per-record: date (AD &amp; BS), JT, book, qty, machine, operator, supervisor, incharge, shift. Latest 500 records.</p>
      <div style="overflow-x:auto"><div id="manpowerTable"></div></div>
    </div>
  </div>
</div>

<!-- DAY DETAIL MODAL -->
<div class="modal fade" id="detailModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title fw-bold" id="modalTitle">—</h5>
          <small class="text-muted" id="modalSub"></small>
        </div>
        <div style="display:flex;gap:5px;margin-left:auto;align-items:center">
          <button class="btn-f btn-grn" id="xlBtn">⬇ Excel</button>
          <button class="btn-f btn-sec" id="pdfBtn">📄 PDF</button>
          <button type="button" class="btn-close ms-2" data-bs-dismiss="modal"></button>
        </div>
      </div>
      <div class="modal-body" id="modalBody">
        <div style="text-align:center;padding:40px;color:var(--text-muted)">
          <div class="spinner" style="margin:0 auto 14px"></div>Loading…
        </div>
      </div>
      <div class="modal-footer">
        <span id="modalTotals" style="font-size:11px;color:var(--text-muted);margin-right:auto"></span>
        <button type="button" class="btn-f btn-sec" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- DEBUG PANEL -->
<button id="debugToggle">🐛 Debug</button>
<div id="debugPanel">
  <div style="color:#e2e8f0;font-weight:700;margin-bottom:5px">Debug — AJAX Test Panel</div>
  <div id="debugLog"></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<!-- Embedded nepali-date-converter v3.4.0 (UMD) -->
<script>
(function (global, factory) {
    typeof exports === 'object' && typeof module !== 'undefined' ? factory(exports) :
    typeof define === 'function' && define.amd ? define(['exports'], factory) :
    (global = typeof globalThis !== 'undefined' ? globalThis : global || self, factory(global.NepaliDate = {}));
})(this, (function (exports) { 'use strict';

    /******************************************************************************
    Copyright (c) Microsoft Corporation.

    Permission to use, copy, modify, and/or distribute this software for any
    purpose with or without fee is hereby granted.

    THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES WITH
    REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF MERCHANTABILITY
    AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY SPECIAL, DIRECT,
    INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES WHATSOEVER RESULTING FROM
    LOSS OF USE, DATA OR PROFITS, WHETHER IN AN ACTION OF CONTRACT, NEGLIGENCE OR
    OTHER TORTIOUS ACTION, ARISING OUT OF OR IN CONNECTION WITH THE USE OR
    PERFORMANCE OF THIS SOFTWARE.
    ***************************************************************************** */

    var __assign = function() {
        __assign = Object.assign || function __assign(t) {
            for (var s, i = 1, n = arguments.length; i < n; i++) {
                s = arguments[i];
                for (var p in s) if (Object.prototype.hasOwnProperty.call(s, p)) t[p] = s[p];
            }
            return t;
        };
        return __assign.apply(this, arguments);
    };

    var dateConfigMap = {
        '2000': {
            Baisakh: 30,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 30,
            Falgun: 29,
            Chaitra: 31,
        },
        '2001': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2002': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2003': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 31,
        },
        '2004': {
            Baisakh: 30,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 30,
            Falgun: 29,
            Chaitra: 31,
        },
        '2005': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2006': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2007': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 31,
        },
        '2008': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 29,
            Mangsir: 30,
            Poush: 30,
            Magh: 29,
            Falgun: 29,
            Chaitra: 31,
        },
        '2009': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2010': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2011': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 31,
        },
        '2012': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 29,
            Mangsir: 30,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2013': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2014': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2015': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 31,
        },
        '2016': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 29,
            Mangsir: 30,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2017': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2018': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2019': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 30,
            Falgun: 29,
            Chaitra: 31,
        },
        '2020': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2021': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2022': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2023': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 30,
            Falgun: 29,
            Chaitra: 31,
        },
        '2024': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2025': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2026': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 31,
        },
        '2027': {
            Baisakh: 30,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 30,
            Falgun: 29,
            Chaitra: 31,
        },
        '2028': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2029': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 32,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2030': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 31,
        },
        '2031': {
            Baisakh: 30,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 30,
            Falgun: 29,
            Chaitra: 31,
        },
        '2032': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2033': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2034': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 31,
        },
        '2035': {
            Baisakh: 30,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 29,
            Mangsir: 30,
            Poush: 30,
            Magh: 29,
            Falgun: 29,
            Chaitra: 31,
        },
        '2036': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2037': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2038': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 31,
        },
        '2039': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 29,
            Mangsir: 30,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2040': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2041': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2042': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 31,
        },
        '2043': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 29,
            Mangsir: 30,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2044': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2045': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2046': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 31,
        },
        '2047': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2048': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2049': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2050': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 30,
            Falgun: 29,
            Chaitra: 31,
        },
        '2051': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2052': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2053': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2054': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 30,
            Falgun: 29,
            Chaitra: 31,
        },
        '2055': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2056': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 32,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2057': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 31,
        },
        '2058': {
            Baisakh: 30,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 30,
            Falgun: 29,
            Chaitra: 31,
        },
        '2059': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2060': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2061': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 31,
        },
        '2062': {
            Baisakh: 30,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 29,
            Mangsir: 30,
            Poush: 29,
            Magh: 30,
            Falgun: 29,
            Chaitra: 31,
        },
        '2063': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2064': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2065': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 31,
        },
        '2066': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 29,
            Mangsir: 30,
            Poush: 30,
            Magh: 29,
            Falgun: 29,
            Chaitra: 31,
        },
        '2067': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2068': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2069': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 31,
        },
        '2070': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 29,
            Mangsir: 30,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2071': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2072': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2073': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 31,
        },
        '2074': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2075': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2076': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2077': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 30,
            Falgun: 29,
            Chaitra: 31,
        },
        '2078': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2079': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2080': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2081': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 30,
            Falgun: 29,
            Chaitra: 31,
        },
        '2082': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2083': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2084': {
            Baisakh: 31,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 29,
            Falgun: 30,
            Chaitra: 31,
        },
        '2085': {
            Baisakh: 30,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 30,
            Falgun: 29,
            Chaitra: 31,
        },
        '2086': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 29,
            Poush: 30,
            Magh: 29,
            Falgun: 30,
            Chaitra: 30,
        },
        '2087': {
            Baisakh: 31,
            Jestha: 31,
            Asar: 32,
            Shrawan: 31,
            Bhadra: 31,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 30,
            Falgun: 30,
            Chaitra: 30,
        },
        '2088': {
            Baisakh: 30,
            Jestha: 31,
            Asar: 32,
            Shrawan: 32,
            Bhadra: 30,
            Aswin: 31,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 30,
            Falgun: 30,
            Chaitra: 30,
        },
        '2089': {
            Baisakh: 30,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 30,
            Falgun: 30,
            Chaitra: 30,
        },
        '2090': {
            Baisakh: 30,
            Jestha: 32,
            Asar: 31,
            Shrawan: 32,
            Bhadra: 31,
            Aswin: 30,
            Kartik: 30,
            Mangsir: 30,
            Poush: 29,
            Magh: 30,
            Falgun: 30,
            Chaitra: 30,
        },
    };

    var Language;
    (function (Language) {
        Language["np"] = "np";
        Language["en"] = "en";
    })(Language || (Language = {}));
    /**
     * The constant storing nepali date month days mappings for each year starting from 2000 BS
     */
    var yearMonthDaysMapping = Object.values(dateConfigMap).map(function (year) {
        return Object.values(year);
    });
    /**
     * Memoizing the days passed for each month in year for faster calculation
     */
    var monthDaysMappings = yearMonthDaysMapping.map(function (yearMappings) {
        var daySum = 0;
        return yearMappings.map(function (monthDays) {
            var monthPassedDays = [monthDays, daySum];
            daySum += monthDays;
            return monthPassedDays;
        });
    }, []);
    /**
     * Ignore
     */
    var daysPassed = 0;
    /**
     * Memoizing the days passed after each year from the epoch time and the sum of days in a year
     */
    var yearDaysMapping = yearMonthDaysMapping.map(function (yearMappings) {
        var daysInYear = yearMappings.reduce(function (acc, x) { return acc + x; }, 0);
        var yearDaysPassed = [daysInYear, daysPassed];
        daysPassed += daysInYear;
        return yearDaysPassed;
    });
    /**
     * Max possible Day
     */
    var MAX_DAY = 33238;
    if (daysPassed !== MAX_DAY) {
        throw new Error('Invalid constant initialization for Nepali Date.');
    }
    /**
     * Min possible Day
     */
    var MIN_DAY = 1;
    /**
     * @ignore
     */
    function getYearIndex(year) {
        return year - EPOCH_YEAR;
    }
    /**
     * @ignore
     */
    function getYearFromIndex(yearIndex) {
        return yearIndex + EPOCH_YEAR;
    }
    /**
     * @ignore
     */
    var EPOCH_YEAR = 2000;
    /**
     * @ignore
     */
    var COMPLETED_DAYS = 1;
    /**
     * @ignore
     */
    var TOTAL_DAYS = 0;
    /**
     * @ignore
     */
    function mod(m, val) {
        while (val < 0) {
            val += m;
        }
        return val % m;
    }
    /**
     * Format Object
     */
    var formatObj = {
        en: {
            day: {
                short: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
                long: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
            },
            month: {
                short: ['Bai', 'Jes', 'Asa', 'Shr', 'Bhd', 'Asw', 'Kar', 'Man', 'Pou', 'Mag', 'Fal', 'Cha'],
                long: [
                    'Baisakh',
                    'Jestha',
                    'Asar',
                    'Shrawan',
                    'Bhadra',
                    'Aswin',
                    'Kartik',
                    'Mangsir',
                    'Poush',
                    'Magh',
                    'Falgun',
                    'Chaitra',
                ],
            },
            date: ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        },
        np: {
            day: {
                short: ['आइत', 'सोम', 'मंगल', 'बुध', 'बिहि', 'शुक्र', 'शनि'],
                long: ['आइतबार', 'सोमबार', 'मंगलबार', 'बुधबार', 'बिहिबार', 'शुक्रबार', 'शनिबार'],
            },
            month: {
                short: ['बै', 'जे', 'अ', 'श्रा', 'भा', 'आ', 'का', 'मं', 'पौ', 'मा', 'फा', 'चै'],
                long: [
                    'बैशाख',
                    'जेठ',
                    'असार',
                    'श्रावण',
                    'भाद्र',
                    'आश्विन',
                    'कार्तिक',
                    'मंसिर',
                    'पौष',
                    'माघ',
                    'फाल्गुण',
                    'चैत्र',
                ],
            },
            date: ['०', '१', '२', '३', '४', '५', '६', '७', '८', '९'],
        },
    };
    /**
     * Epoch in english date
     */
    var beginEnglish = {
        year: 1943,
        month: 3,
        date: 13,
        day: 3,
    };
    /**
     * `findPassedDays` calculates the days passed from the epoch time.
     *  If the days are beyond boundary MIN_DAY and MAX_DAY throws error.
     * @param year Year between 2000-2009 of nepali date
     * @param month Month Index which can be negative or positive and can be any number but should be within range of year 2000-2090
     * @param date Date which can be negative or positive and can be any number but should be within range of year 2000-2090
     * @returns Number of days passed since epoch time from the given date,month and year.
     */
    function findPassedDays(year, month, date) {
        try {
            var yearIndex = getYearIndex(year);
            var pastYearDays = yearDaysMapping[yearIndex][COMPLETED_DAYS];
            var extraMonth = mod(12, month);
            var extraYear = Math.floor(month / 12);
            var pastMonthDays = yearDaysMapping[yearIndex + extraYear][COMPLETED_DAYS] -
                pastYearDays +
                monthDaysMappings[yearIndex + extraYear][extraMonth][COMPLETED_DAYS];
            var daysPassed_1 = pastYearDays + pastMonthDays + date;
            if (daysPassed_1 < MIN_DAY || daysPassed_1 > MAX_DAY) {
                throw new Error();
            }
            return daysPassed_1;
        }
        catch (_a) {
            throw new Error("The date doesn't fall within 2000/01/01 - 2090/12/30");
        }
    }
    /**
     * `mapDaysToDate` finds the date where the the given day lies from the epoch date
     * If the daysPassed is on the date 2000/01/01 then it will be 1. Similarly, every day adds on from then
     * If the days are beyond boundary MIN_DAY and MAX_DAY throws error.
     * @param daysPassed The number of days passed since nepali date epoch time
     * @returns date values in object implementing IYearMonthDate interface
     */
    function mapDaysToDate(daysPassed) {
        if (daysPassed < MIN_DAY || daysPassed > MAX_DAY) {
            throw new Error("The epoch difference is not within the boundaries ".concat(MIN_DAY, " - ").concat(MAX_DAY));
        }
        var yearIndex = yearDaysMapping.findIndex(function (year) {
            return daysPassed > year[COMPLETED_DAYS] && daysPassed <= year[COMPLETED_DAYS] + year[TOTAL_DAYS];
        });
        var monthRemainder = daysPassed - yearDaysMapping[yearIndex][COMPLETED_DAYS];
        var monthIndex = monthDaysMappings[yearIndex].findIndex(function (month) {
            return monthRemainder > month[COMPLETED_DAYS] &&
                monthRemainder <= month[COMPLETED_DAYS] + month[TOTAL_DAYS];
        });
        var date = monthRemainder - monthDaysMappings[yearIndex][monthIndex][COMPLETED_DAYS];
        return {
            year: getYearFromIndex(yearIndex),
            month: monthIndex,
            date: date,
        };
    }
    function findPassedDaysAD(year, month, date) {
        var timeDiff = Math.abs(Date.UTC(year, month, date) - Date.UTC(beginEnglish.year, beginEnglish.month, beginEnglish.date));
        var diffDays = Math.ceil(timeDiff / (1000 * 3600 * 24));
        return diffDays;
    }
    function mapDaysToDateAD(daysPassed) {
        var mappedDate = new Date(Date.UTC(1943, 3, 13 + daysPassed));
        return {
            year: mappedDate.getUTCFullYear(),
            month: mappedDate.getUTCMonth(),
            date: mappedDate.getUTCDate(),
            day: mappedDate.getUTCDay(),
        };
    }
    function convertToAD(bsDateObject) {
        try {
            var daysPassed_2 = findPassedDays(bsDateObject.year, bsDateObject.month, bsDateObject.date);
            var BS = mapDaysToDate(daysPassed_2);
            var AD = mapDaysToDateAD(daysPassed_2);
            return {
                AD: AD,
                BS: __assign(__assign({}, BS), { day: AD.day }),
            };
        }
        catch (_a) {
            throw new Error("The date doesn't fall within 2000/01/01 - 2090/12/30");
        }
    }
    function convertToBS(adDateObject) {
        try {
            var daysPassed_3 = findPassedDaysAD(adDateObject.getFullYear(), adDateObject.getMonth(), adDateObject.getDate());
            var BS = mapDaysToDate(daysPassed_3);
            var AD = mapDaysToDateAD(daysPassed_3);
            return {
                AD: AD,
                BS: __assign(__assign({}, BS), { day: AD.day }),
            };
        }
        catch (_a) {
            throw new Error("The date doesn't fall within 2000/01/01 - 2090/12/30");
        }
    }
    function mapLanguageNumber(dateNumber, language) {
        return dateNumber
            .split('')
            .map(function (num) { return formatObj[language].date[parseInt(num, 10)]; })
            .join('');
    }
    function format(bsDate, stringFormat, language) {
        return stringFormat
            .replace(/((\\[MDYd])|D{1,2}|M{1,4}|Y{2,4}|d{1,3})/g, function (match, _, matchedString) {
            var _a;
            switch (match) {
                case 'D':
                    return mapLanguageNumber(bsDate.date.toString(), language);
                case 'DD':
                    return mapLanguageNumber(bsDate.date.toString().padStart(2, '0'), language);
                case 'M':
                    return mapLanguageNumber((bsDate.month + 1).toString(), language);
                case 'MM':
                    return mapLanguageNumber((bsDate.month + 1).toString().padStart(2, '0'), language);
                case 'MMM':
                    return formatObj[language].month.short[bsDate.month];
                case 'MMMM':
                    return formatObj[language].month.long[bsDate.month];
                case 'YY':
                    return mapLanguageNumber(bsDate.year.toString().slice(-2), language);
                case 'YYY':
                    return mapLanguageNumber(bsDate.year.toString().slice(-3), language);
                case 'YYYY':
                    return mapLanguageNumber(bsDate.year.toString(), language);
                case 'd':
                    return mapLanguageNumber(((_a = bsDate.day) === null || _a === void 0 ? void 0 : _a.toString()) || '0', language);
                case 'dd':
                    return formatObj[language].day.short[bsDate.day || 0];
                case 'ddd':
                    return formatObj[language].day.long[bsDate.day || 0];
                default:
                    return matchedString.replace('/', '');
            }
        })
            .replace(/\\/g, '');
    }
    function parse(dateString) {
        var OFFICIAL_FORMAT = /(\d{4})\s*([/-]|\s+)\s*(\d{1,2})\s*([/-]|\s+)\s*(\d{1,2})/;
        var GEORGIAN_FORMAT = /(\d{1,2})\s*([/-]|\s+)\s*(\d{1,2})\s*([/-]|\s+)\s*(\d{4})/;
        var match;
        match = dateString.match(OFFICIAL_FORMAT);
        if (match !== null) {
            return {
                year: parseInt(match[1], 10),
                month: parseInt(match[3], 10) - 1,
                date: parseInt(match[5], 10),
            };
        }
        match = dateString.match(GEORGIAN_FORMAT);
        if (match !== null) {
            return {
                year: parseInt(match[5], 10),
                month: parseInt(match[3], 10) - 1,
                date: parseInt(match[1], 10),
            };
        }
        throw new Error('Invalid date format');
    }

    var dateSymbol = Symbol('Date');
    var daySymbol = Symbol('Day');
    var yearSymbol = Symbol('Year');
    var monthSymbol = Symbol('MonthIndex');
    var jsDateSymbol = Symbol('JsDate');
    var convertToBSMethod = Symbol('convertToBS()');
    var convertToADMethod = Symbol('convertToAD()');
    var setAdBs = Symbol('setADBS()');
    var setDayYearMonth = Symbol('setDayYearMonth()');
    var NepaliDate = /** @class */ (function () {
        function NepaliDate() {
            var constructorError = new Error('Invalid constructor arguments');
            if (arguments.length === 0) {
                this[convertToBSMethod](new Date());
            }
            else if (arguments.length === 1) {
                var argument = arguments[0];
                switch (typeof argument) {
                    case 'number':
                        this[convertToBSMethod](new Date(argument));
                        break;
                    case 'string':
                        var _a = parse(argument), date = _a.date, year = _a.year, month = _a.month;
                        this[setDayYearMonth](year, month, date);
                        this[convertToADMethod]();
                        break;
                    case 'object':
                        if (argument instanceof Date) {
                            this[convertToBSMethod](argument);
                        }
                        else {
                            throw constructorError;
                        }
                        break;
                    default:
                        throw constructorError;
                }
            }
            else if (arguments.length <= 3) {
                this[setDayYearMonth](arguments[0], arguments[1], arguments[2]);
                this[convertToADMethod]();
            }
            else {
                throw constructorError;
            }
        }
        NepaliDate.prototype[setDayYearMonth] = function (year, month, date, day) {
            if (month === void 0) { month = 0; }
            if (date === void 0) { date = 1; }
            if (day === void 0) { day = 0; }
            this[yearSymbol] = year;
            this[monthSymbol] = month;
            this[dateSymbol] = date;
            this[daySymbol] = day;
        };
        /**
         * Returns Javascript Date converted from nepali date.
         */
        NepaliDate.prototype.toJsDate = function () {
            return this[jsDateSymbol];
        };
        /**
         * Get Nepali date for the month
         */
        NepaliDate.prototype.getDate = function () {
            return this[dateSymbol];
        };
        /**
         * Get Nepali date year.
         */
        NepaliDate.prototype.getYear = function () {
            return this[yearSymbol];
        };
        /**
         * Get Week day index for the date.
         */
        NepaliDate.prototype.getDay = function () {
            return this[daySymbol];
        };
        /**
         * Get Nepali month index.
         *
         * ```
         * Baisakh => 0
         * Jestha => 1
         * Asar => 2
         * Shrawan => 3
         * Bhadra => 4
         * Aswin => 5
         * Kartik => 6
         * Mangsir => 7
         * Poush => 8
         * Magh => 9
         * Falgun => 10
         * Chaitra => 11
         * ```
         */
        NepaliDate.prototype.getMonth = function () {
            return this[monthSymbol];
        };
        /**
         * Returns an object with AD and BS object implementing IYearMonthDate
         *
         * Example:
         *
         * ```js
         * {
         *     BS: {
         *         year: 2052,
         *         month: 10,
         *         date: 10,
         *         day: 0
         *     },
         *     AD: {
         *         year: 2019,
         *         month: 10,
         *         date: 10,
         *         day: 0
         *     },
         *
         * }
         * ```
         */
        NepaliDate.prototype.getDateObject = function () {
            return {
                BS: this.getBS(),
                AD: this.getAD(),
            };
        };
        /**
         * Returns Nepali date fields in an object implementing IYearMonthDate
         *
         * ```js
         * {
         *     year: 2052,
         *     month: 10,
         *     date: 10,
         *     day: 0
         * }
         * ```
         */
        NepaliDate.prototype.getBS = function () {
            return {
                year: this[yearSymbol],
                month: this[monthSymbol],
                date: this[dateSymbol],
                day: this[daySymbol],
            };
        };
        /**
         * Returns AD date fields in an object implementing IYearMonthDate
         *
         * ```js
         * {
         *     year: 2019,
         *     month: 10,
         *     date: 10,
         *     day: 0
         * }
         * ```
         */
        NepaliDate.prototype.getAD = function () {
            return {
                year: this[jsDateSymbol].getFullYear(),
                month: this[jsDateSymbol].getMonth(),
                date: this[jsDateSymbol].getDate(),
                day: this[jsDateSymbol].getDay(),
            };
        };
        /**
         * Set date in the current date object. It can be positive or negative. Positive values within the month
         * will update the date only and more then month mill increment month and year. Negative value will deduct month and year depending on the value.
         * It is similar to javascript Date API.
         *
         * Example:
         * ```js
         * let a = new NepaliDate(2054,10,10);
         * a.setDate(11); // will make date NepaliDate(2054,10,11);
         * a.setDate(-1); // will make date NepaliDate(2054,9,29);
         * a.setDate(45); // will make date NepaliDate(2054,10,15);
         * ```
         * @param date positive or negative integer value to set date
         */
        NepaliDate.prototype.setDate = function (date) {
            var oldDate = this[dateSymbol];
            try {
                this[dateSymbol] = date;
                this[convertToADMethod]();
            }
            catch (e) {
                this[dateSymbol] = oldDate;
                throw e;
            }
        };
        /**
         * Set month in the current date object. It can be positive or negative. Positive values within the month
         * will update the month only and more then month mill increment month and year. Negative value will deduct month and year depending on the value.
         * It is similar to javascript Date API.
         *
         * Example:
         * ```js
         * let a = new NepaliDate(2054,10,10);
         * a.setMonth(1); // will make date NepaliDate(2054,11,10);
         * a.setMonth(-1); // will make date NepaliDate(2053,11,10);
         * a.setMonth(12); // will make date NepaliDate(2054,0,10);
         * ```
         * @param date positive or negative integer value to set month
         */
        NepaliDate.prototype.setMonth = function (month) {
            var oldMonth = this[monthSymbol];
            try {
                this[monthSymbol] = month;
                this[convertToADMethod]();
            }
            catch (e) {
                this[monthSymbol] = oldMonth;
                throw e;
            }
        };
        /**
         * Set year in the current date object. It only takes positive value i.e Nepali Year
         *
         * Example:
         * ```js
         * let a = new NepaliDate(2054,10,10);
         * a.setYear(2053); // will make date NepaliDate(2053,10,15);
         * ```
         * @param date positive integer value to set year
         */
        NepaliDate.prototype.setYear = function (year) {
            var oldYear = this[yearSymbol];
            try {
                this[yearSymbol] = year;
                this[convertToADMethod]();
            }
            catch (e) {
                this[yearSymbol] = oldYear;
                throw e;
            }
        };
        /**
         * Format Nepali date string based on format string.
         * ```
         * YYYY - 4 digit of year (2077)
         * YYY  - 3 digit of year (077)
         * YY   - 2 digit of year (77)
         * M    - month number (1 - 12)
         * MM   - month number with 0 padding (01 - 12)
         * MMM  - short month name (Bai, Jes, Asa, Shr, etc.)
         * MMMM - full month name (Baisakh, Jestha, Asar, ...)
         * D    - Day of Month (1, 2, ... 31, 32)
         * DD   - Day of Month with zero padding (01, 02, ...)
         * d    - Week day (0, 1, 2, 3, 4, 5, 6)
         * dd   - Week day in short format (Sun, Mon, ..)
         * ddd  - Week day in long format (Sunday, Monday, ...)
         * ```
         * Set language to 'np' for nepali format. The strings can be combined in any way to create desired format.
         * ```js
         * let a = new NepaliDate(2054,10,10);
         * a.format('YYYY/MM/DD') // '2054/11/10'
         * a.format('YYYY MM DD') // '2054 11 10'
         * a.format('YYYY') // '2054'
         * a.format('ddd DD, MMMM YYYY') // 'Sunday 10, Falgun 2054'
         * a.format('To\\day is ddd DD, MMMM YYYY') // 'Today is Sunday 10, Falgun 2054', Note: use '\\' to escape [YMDd]
         * a.format('DD/MM/YYYY', 'np') //' १०/११/२०५४'
         * a.format('dd', 'np') // 'आइतबार'
         * a.format('ddd DD, MMMM YYYY','np') // 'आइतबार १०, फाल्गुण २०५४'
         * // Set static variable to 'np' for default Nepali language
         * NepaliDate.language = 'np'
         * a.format('ddd DD, MMMM YYYY') // 'आइतबार १०, फाल्गुण २०५४'
         * ```
         * @param formatString
         * @param language en | np
         */
        NepaliDate.prototype.format = function (formatString, language) {
            if (language === void 0) { language = NepaliDate.language; }
            return format(this.getBS(), formatString, language);
        };
        /**
         * Returns new Nepali Date from the string date format
         * Similar to calling constructor with string parameter
         * @param dateString
         */
        NepaliDate.parse = function (dateString) {
            var _a = parse(dateString), date = _a.date, year = _a.year, month = _a.month;
            return new NepaliDate(year, month, date);
        };
        /**
         * Returns new Nepali Date converted form current day date.
         * Similar to calling empty constructor
         */
        NepaliDate.now = function () {
            return new NepaliDate();
        };
        /**
         * Returns new converted Nepali Date from the provided Javascript Date.
         * It is similar to passing string as constructor
         * @param date
         */
        NepaliDate.fromAD = function (date) {
            return new NepaliDate(date);
        };
        NepaliDate.prototype[convertToBSMethod] = function (date) {
            var _a = convertToBS(date), AD = _a.AD, BS = _a.BS;
            this[setAdBs](AD, BS);
        };
        NepaliDate.prototype[setAdBs] = function (AD, BS) {
            this[setDayYearMonth](BS.year, BS.month, BS.date, BS.day);
            this[jsDateSymbol] = new Date(AD.year, AD.month, AD.date);
        };
        NepaliDate.prototype[convertToADMethod] = function () {
            var _a = convertToAD({
                year: this[yearSymbol],
                month: this[monthSymbol],
                date: this[dateSymbol],
            }), AD = _a.AD, BS = _a.BS;
            this[setAdBs](AD, BS);
        };
        NepaliDate.prototype.valueOf = function () {
            return this[jsDateSymbol].getTime();
        };
        NepaliDate.prototype.toString = function () {
            return this.format('ddd DD, MMMM YYYY');
        };
        /**
         * Default language for formatting. Set the value to 'np' for default nepali formatting.
         */
        NepaliDate.language = Language.en;
        return NepaliDate;
    }());

    exports.dateConfigMap = dateConfigMap;
    exports["default"] = NepaliDate;

    Object.defineProperty(exports, '__esModule', { value: true });

}));
</script>

<script>
/* ════════════════════════════════════════════════════════════════════
   CALENDAR PRODUCTION REPORT — JavaScript v5
   ──────────────────────────────────────────────────────────────────
   Key logic clarification (per business spec):
     • Job Ticket print_qty = target number of books (e.g. 100,000)
     • Each forma must be printed to the JT target individually
     • Total FP qty across all formas ≠ books made (it's intermediate)
     • BP qty = actual semi-finished books
   ════════════════════════════════════════════════════════════════════ */

const _ND = (typeof NepaliDate !== 'undefined' && NepaliDate.default)
            ? NepaliDate.default
            : (typeof NepaliDate !== 'undefined' ? NepaliDate : null);

const SELF = <?= json_encode($self_url) ?>;
let CY = <?= $cal_year ?>, CM = <?= $cal_month ?>;
let calData = {}, activeDate = null, rangeFrom = '', rangeTo = '';
let trendData = null, trendBucket = 'month', trendChart = null, fpBpChart = null;
let currentView = 'view-calendar';

const MN = ['January','February','March','April','May','June',
            'July','August','September','October','November','December'];
// Nepali months — 0-based index in nepali-date-converter
const NEP_FULL = ['Baisakh','Jestha','Ashadh','Shrawan','Bhadra','Ashwin',
                  'Kartik','Mangsir','Poush','Magh','Falgun','Chaitra'];
const NEP_SHORT = ['Bai','Jes','Ash','Shr','Bha','Ash','Kar','Man','Pou','Mag','Fal','Cha'];

// BS Fiscal year: starts Shrawan (month index 3), ends Ashadh (index 2) next year
// Q1 = Shrawan-Bhadra-Ashwin   (months 3,4,5)
// Q2 = Kartik-Mangsir-Poush    (months 6,7,8)
// Q3 = Magh-Falgun-Chaitra     (months 9,10,11)
// Q4 = Baisakh-Jestha-Ashadh   (months 0,1,2)
function bsQuarterOf(monthIdx) {
    if (monthIdx <= 2) return 4;
    if (monthIdx <= 5) return 1;
    if (monthIdx <= 8) return 2;
    return 3;
}
// BS half-year: H1 = Shrawan-Poush (3-8), H2 = Magh-Ashadh (9,10,11,0,1,2)
function bsHalfOf(monthIdx) {
    return (monthIdx >= 3 && monthIdx <= 8) ? 1 : 2;
}
// BS fiscal year for a given BS year/month
// If month >= Shrawan (3), FY = year-(year+1); else FY = (year-1)-year
function bsFiscalYear(bsYear, bsMonth) {
    return bsMonth >= 3 ? bsYear : bsYear - 1;
}

const pad = n => String(n).padStart(2,'0');
const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
function fmt(n){ n=parseInt(n)||0;
  if(n>=1e6) return (n/1e6).toFixed(1).replace(/\.0$/,'')+'M';
  if(n>=1000) return (n/1000).toFixed(1).replace(/\.0$/,'')+'K';
  return n.toLocaleString(); }

// ── Nepali conversion ──────────────────────────────────────────────
function engToNep(y, m, d) {
    if (!_ND) return null;
    try { const nd = new _ND(new Date(y, m-1, d));
          return { year:nd.getYear(), month:nd.getMonth(), date:nd.getDate() }; }
    catch(e){ return null; }
}
function engStrToNep(adStr) {
    if (!adStr) return null;
    const p = adStr.split('-');
    if (p.length !== 3) return null;
    return engToNep(+p[0], +p[1], +p[2]);
}
function nepToEngStr(bsStr) {
    if (!_ND) return null;
    try {
        const p = bsStr.split('-');
        if (p.length !== 3) return null;
        const nd = _ND.parse(`${p[0]}-${parseInt(p[1])}-${parseInt(p[2])}`);
        const ad = nd.toJsDate();
        return `${ad.getFullYear()}-${pad(ad.getMonth()+1)}-${pad(ad.getDate())}`;
    } catch(e){ return null; }
}
function nepFmt(nep) {
    // Returns "YYYY-MM-DD BS"  (month is 0-based internally, display as 1-based)
    return nep ? `${nep.year}-${pad(nep.month+1)}-${pad(nep.date)}` : '';
}
function nepMonthLabel(y, m) {
    if (!_ND) return '—';
    const r = engToNep(y, m, 15);
    return r ? NEP_FULL[r.month] + ' ' + r.year : '—';
}

// ── BS date pickers (typed input → auto-convert) ───────────────────
function setupBsInput(bsId, adId) {
    const bsEl = document.getElementById(bsId), adEl = document.getElementById(adId);
    bsEl.addEventListener('change', () => {
        if (!bsEl.value) { adEl.value=''; return; }
        const ad = nepToEngStr(bsEl.value); if (ad) adEl.value = ad;
    });
    adEl.addEventListener('change', () => {
        if (!adEl.value) { bsEl.value=''; return; }
        const r = engStrToNep(adEl.value); if (r) bsEl.value = nepFmt(r);
    });
}
setupBsInput('dateFromNep','dateFromEng');
setupBsInput('dateToNep','dateToEng');

/* ═══════════════════════════════════════════════════════════════════
   VIEW SWITCHING
   ═══════════════════════════════════════════════════════════════════ */
document.querySelectorAll('.vtab').forEach(t => {
    t.addEventListener('click', () => {
        const vid = t.dataset.view;
        document.querySelectorAll('.vtab').forEach(x => x.classList.remove('active'));
        document.querySelectorAll('.view-container').forEach(x => x.classList.remove('active'));
        t.classList.add('active');
        document.getElementById(vid).classList.add('active');
        currentView = vid;
        if (vid === 'view-trends')   loadTrendData();
        if (vid === 'view-manpower') loadManpower();
    });
});

/* ═══════════════════════════════════════════════════════════════════
   CALENDAR
   ═══════════════════════════════════════════════════════════════════ */
function buildCalendar() {
    const grid = document.getElementById('dayGrid');
    grid.innerHTML = '';
    document.getElementById('calTitle').textContent = MN[CM-1] + ' ' + CY;
    document.getElementById('nepBadge').textContent  = 'BS ' + nepMonthLabel(CY, CM);
    document.getElementById('jumpMonth').value        = `${CY}-${pad(CM)}`;

    const today = new Date();
    const todayStr = `${today.getFullYear()}-${pad(today.getMonth()+1)}-${pad(today.getDate())}`;
    const firstDow = new Date(CY, CM-1, 1).getDay();
    const daysInMo = new Date(CY, CM, 0).getDate();
    const prevDays = new Date(CY, CM-1, 0).getDate();
    const numRows  = Math.ceil((firstDow + daysInMo) / 7);
    grid.style.gridTemplateRows = `repeat(${numRows},1fr)`;

    let alertCount = 0;
    for (let i=0; i<firstDow; i++) grid.appendChild(ghostCell(prevDays-firstDow+i+1));
    for (let d=1; d<=daysInMo; d++) {
        const ds = `${CY}-${pad(CM)}-${pad(d)}`;
        const data = calData[ds] || {fp:0,bp:0,deno:0,d2m:0,jt:0,book_codes:'',alerts:[]};
        if (data.alerts && data.alerts.length) alertCount += data.alerts.length;
        grid.appendChild(makeCell(ds, d, new Date(CY,CM-1,d).getDay(), ds===todayStr, data));
    }
    const trailing = numRows*7 - (firstDow+daysInMo);
    for (let i=1; i<=trailing; i++) grid.appendChild(ghostCell(i));

    const ab = document.getElementById('alertBadge');
    if (alertCount > 0) { ab.textContent=`⚠ ${alertCount} Alert${alertCount>1?'s':''}`; ab.style.display='inline'; }
    else ab.style.display='none';
}

function ghostCell(dn) {
    const el = document.createElement('div'); el.className='dc other-mo';
    el.innerHTML=`<div class="dc-head"><span class="dc-eng" style="opacity:.3">${dn}</span></div>`;
    return el;
}

function makeCell(ds, dn, dow, isToday, data) {
    const isSat=dow===6, isSun=dow===0;
    const hasData=data.fp||data.bp||data.deno||data.d2m;
    const hasAlert=data.alerts&&data.alerts.length>0;
    const inRange=rangeFrom&&rangeTo&&ds>=rangeFrom&&ds<=rangeTo;
    const isRS=rangeFrom&&ds===rangeFrom, isRE=rangeTo&&ds===rangeTo;

    const el=document.createElement('div');
    el.className='dc'+(isToday?' is-today':'')+(isSat?' is-sat':'')+(isSun?' is-sun':'')
        +(hasData?' has-data':'')+(hasAlert?' has-alert':'')
        +(isRS?' range-start':'')+(isRE?' range-end':'')
        +(inRange&&!isRS&&!isRE?' in-range':'');

    const nep = engToNep(CY, CM, dn);
    const nepHTML = nep ? `<div class="dc-nep-wrap">
        <span class="dc-nep-mo">${NEP_SHORT[nep.month]}</span>
        <span class="dc-nep-day">${nep.date}</span></div>` : '';

    const booksHTML = data.book_codes
        ? `<div class="dc-books" title="${esc(data.book_codes)}">${esc(data.book_codes)}</div>`
        : '';

    el.innerHTML = `
      <div class="dc-head"><span class="dc-eng">${dn}</span>${nepHTML}</div>
      ${hasAlert ? `<span class="alert-flag" title="${esc((data.alerts||[]).map(a=>a.machine+'▼'+a.drop_pct+'%').join(', '))}">🚨</span>` : ''}
      ${booksHTML}
      <div class="dc-metrics">
        ${mRow('var(--fp)','FP',data.fp)}
        ${mRow('var(--bp)','BP',data.bp)}
        ${mRow('var(--dn)','DN',data.deno)}
        ${mRow('var(--d2m)','D2M',data.d2m)}
      </div>
      <div class="dc-foot">
        <span style="color:var(--accent)">●</span><span>${data.jt} JT</span>
        ${hasAlert?`<span style="color:var(--danger);margin-left:auto">⚠${data.alerts.length}</span>`:''}
      </div>`;

    el.addEventListener('click', () => openModal(ds, nep));
    return el;
}
function mRow(color, label, val) {
    const v=parseInt(val)||0, cls=v===0?'zero':'';
    return `<div class="dc-row">
      <span class="dc-dot" style="background:${color}"></span>
      <span class="dc-lbl">${label}</span>
      <span class="dc-val ${cls}">${v===0?'—':fmt(v)}</span></div>`;
}

function loadMonth() {
    document.getElementById('calOverlay').classList.add('show');
    const fy=document.getElementById('filterFY').value;
    const book=document.getElementById('filterBook').value;
    const df=document.getElementById('dateFromEng').value;
    const dt=document.getElementById('dateToEng').value;
    rangeFrom=df; rangeTo=dt;
    document.getElementById('rangeInfo').textContent=(df||dt)?`📅 ${df||'start'} → ${dt||'end'}`:'';

    const p = new URLSearchParams({ajax:'month_data',year:CY,month:CM,fiscal_year:fy,book_code:book,date_from:df,date_to:dt});
    fetch(SELF+'?'+p)
        .then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
        .then(j=>{ dbg('month_data',j); calData=j.success?(j.days||{}):{};
                   buildCalendar(); if(j.success) updateSummary(j.summary||{}); })
        .catch(err=>{ dbg('month_data ERR',err.message); calData={}; buildCalendar(); })
        .finally(()=>document.getElementById('calOverlay').classList.remove('show'));
}
function updateSummary(s){
    document.getElementById('s-fp').textContent=fmt(s.fp_total||0);
    document.getElementById('s-bp').textContent=fmt(s.bp_total||0);
    document.getElementById('s-dn').textContent=fmt(s.deno_total||0);
    document.getElementById('s-d2m').textContent=fmt(s.d2m_total||0);
    document.getElementById('s-jt').textContent=fmt(s.jt_total||0);
}

/* ═══════════════════════════════════════════════════════════════════
   DAY DETAIL MODAL
   ═══════════════════════════════════════════════════════════════════ */
const modalBS = new bootstrap.Modal(document.getElementById('detailModal'));

function openModal(ds, nep) {
    activeDate = ds;
    const bsStr = nep ? nepFmt(nep) + ' BS' : '';
    document.getElementById('modalTitle').textContent = `Production Detail — ${ds}`;
    document.getElementById('modalSub').textContent   = bsStr ? `📅 Nepali: ${bsStr}` : '';
    document.getElementById('modalBody').innerHTML    =
        '<div style="text-align:center;padding:40px;color:var(--text-muted)"><div class="spinner" style="margin:0 auto 14px"></div>Loading…</div>';
    document.getElementById('modalTotals').textContent = '';
    modalBS.show();

    const fy=document.getElementById('filterFY').value;
    const book=document.getElementById('filterBook').value;
    const p=new URLSearchParams({ajax:'day_detail',date:ds,fiscal_year:fy,book_code:book});
    fetch(SELF+'?'+p)
        .then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
        .then(j=>{ dbg('day_detail',j);
            if(!j.success){ document.getElementById('modalBody').innerHTML=`<div class="alert alert-danger"><strong>SQL Error:</strong><br><code>${esc(j.message)}</code></div>`; return; }
            renderDetail(j); })
        .catch(e=>{ document.getElementById('modalBody').innerHTML=`<div class="alert alert-danger">Network error: ${esc(e.message)}</div>`; });
}

function renderDetail(data) {
    const fp=data.fp_rows||[], bp=data.bp_rows||[],
          dn=data.deno_rows||[], d2m=data.d2m_rows||[],
          mc=data.machine_summary||[], alts=data.trend_alerts||[],
          mtrend=data.machine_trend||{}, bpByJt=data.bp_by_jt||{};

    let fpTot=0, bpTot=0, dnTot=0, d2mTot=0;

    let html = `<div class="tab-bar">
      <button class="tab-btn active" onclick="switchTab(this,'tab-fp')">🖨 FP Records${fp.length?` (${fp.length})`:''}</button>
      <button class="tab-btn" onclick="switchTab(this,'tab-bp')">📦 BP Records${bp.length?` (${bp.length})`:''}</button>
      <button class="tab-btn" onclick="switchTab(this,'tab-dn')">🚚 Deno${dn.length?` (${dn.length})`:''}</button>
      <button class="tab-btn" onclick="switchTab(this,'tab-d2m')">🏭 D2M${d2m.length?` (${d2m.length})`:''}</button>
      <button class="tab-btn" onclick="switchTab(this,'tab-machine')">🔧 Machines</button>
      <button class="tab-btn" onclick="switchTab(this,'tab-trend')">📈 Daily Trends</button>
      ${alts.length?`<button class="tab-btn" style="color:var(--danger)" onclick="switchTab(this,'tab-alerts')">🚨 Alerts (${alts.length})</button>`:''}
    </div>`;

    /* ── Tab: FP records (per individual print entry) ─────────── */
    html += `<div class="tab-pane active" id="tab-fp">`;
    if (alts.length) html += alertBannerHTML(alts);
    if (fp.length) {
        html += `<table class="dt" id="t-fp"><thead><tr>
          <th>JT Code</th><th>Book Code</th><th>Book Name</th><th>Class</th>
          <th class="r">JT Target<br><small>(books)</small></th>
          <th class="r">FP Qty<br><small>(this entry)</small></th>
          <th class="r">Book Packed<br><small>(JT-cum.)</small></th>
          <th>Machine</th><th>Operator</th><th>Supervisor</th><th>Incharge</th>
          <th>Shift</th><th>BS Date</th><th>Status</th>
        </tr></thead><tbody>`;
        fp.forEach(r => {
            const fq=parseInt(r.fp_qty)||0;
            const tq=parseInt(r.jt_target_qty)||0;
            const bookPacked=parseInt(bpByJt[r.jt_id])||0;
            fpTot += fq;
            const bpPct = tq>0 ? Math.min(100, Math.round(bookPacked/tq*100)) : 0;
            const isAlt = alts.some(a=>a.machine===r.machine_name);
            html += `<tr${isAlt?' class="machine-alert-row"':''}>
              <td><span class="tag tag-jt">${esc(r.job_ticket_code)}</span></td>
              <td><span class="tag tag-fp">${esc(r.book_code)}</span></td>
              <td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(r.book_name)}">${esc(r.book_name)}</td>
              <td>${r.class_level||'—'}</td>
              <td class="r" style="color:var(--text-muted)">${tq?tq.toLocaleString():'—'}</td>
              <td class="r" style="color:var(--fp);font-size:12px">${fq.toLocaleString()}</td>
              <td class="r" style="color:var(--bp)">${bookPacked.toLocaleString()}${tq?` <small style="color:var(--text-muted)">(${bpPct}%)</small>`:''}</td>
              <td>${esc(r.machine_name||'—')}${isAlt?' ⚠':''}</td>
              <td>${esc(r.operator_name||'—')}</td>
              <td>${esc(r.supervisor_name||'—')}</td>
              <td>${esc(r.incharge_name||'—')}</td>
              <td>${esc(r.shift_name||'—')}</td>
              <td style="font-size:10px">${esc(r.date_nep||'—')}</td>
              <td><span class="sb ${r.status?'sb-a':'sb-p'}">${r.status?'Active':'Pending'}</span></td>
            </tr>`;
        });
        html += `</tbody><tfoot><tr>
          <td colspan="5" style="font-weight:700;text-align:right">FP Sub-total (intermediate)</td>
          <td class="r" style="font-weight:800;color:var(--fp)">${fpTot.toLocaleString()}</td>
          <td colspan="8"></td></tr></tfoot></table>
          <p style="margin-top:10px;font-size:10px;color:var(--text-muted);background:#fffbeb;border-left:3px solid var(--warning);padding:8px 10px;border-radius:4px">
            <strong>Note:</strong> FP qty is intermediate forma-sheet output, not "books made". Each forma of a JT must reach the JT target individually. Look at the Book Packed column for finished books on this JT.
          </p>`;
    } else html += `<p style="color:var(--text-muted);padding:16px">No Forma Printing records for this date.</p>`;
    html += `</div>`;

    /* ── Tab: BP records ────────────────────────────────────── */
    html += `<div class="tab-pane" id="tab-bp">`;
    if (bp.length) {
        html += `<table class="dt" id="t-bp"><thead><tr>
          <th>JT Code</th><th>Book Code</th>
          <th class="r">JT Target</th><th class="r">BP Qty<br><small>(this entry)</small></th><th class="r">%</th>
          <th>Operator</th><th>Supervisor</th><th>Incharge</th>
          <th>AD Date</th><th>BS Date</th><th>Status</th>
        </tr></thead><tbody>`;
        bp.forEach(r => {
            const bq=parseInt(r.bp_qty)||0, tq=parseInt(r.jt_target_qty)||0;
            bpTot += bq;
            const pct=tq>0?Math.min(100,Math.round(bq/tq*100)):0;
            const pctColor=pct>=90?'var(--success)':pct>=50?'var(--warning)':'var(--danger)';
            const ps=r.packing_status||'';
            const psCls={'active':'sb-a','completed':'sb-d','pending':'sb-p','cancelled':'sb-c'}[ps.toLowerCase()]||'sb-a';
            html += `<tr>
              <td><span class="tag tag-jt">${esc(r.job_ticket_code)}</span></td>
              <td><span class="tag tag-bp">${esc(r.book_code)}</span></td>
              <td class="r" style="color:var(--text-muted)">${tq?tq.toLocaleString():'—'}</td>
              <td class="r" style="color:var(--bp);font-size:12px">${bq.toLocaleString()}</td>
              <td class="r"><span style="color:${pctColor};font-weight:800">${tq?pct+'%':'—'}</span></td>
              <td>${esc(r.operator_name||'—')}</td>
              <td>${esc(r.supervisor_name||'—')}</td>
              <td>${esc(r.incharge_name||'—')}</td>
              <td style="font-size:10px">${esc(r.date_eng||'—')}</td>
              <td style="font-size:10px">${esc(r.date_nep||'—')}</td>
              <td><span class="sb ${psCls}">${esc(ps||'—')}</span></td>
            </tr>`;
        });
        html += `</tbody><tfoot><tr>
          <td colspan="3" style="font-weight:700;text-align:right">Total Packed (this date)</td>
          <td class="r" style="font-weight:800;color:var(--bp)">${bpTot.toLocaleString()}</td>
          <td colspan="7"></td></tr></tfoot></table>`;
    } else html += `<p style="color:var(--text-muted);padding:16px">No Book Packing records for this date.</p>`;
    html += `</div>`;

    /* ── Tab: Deno ──────────────────────────────────────────── */
    html += `<div class="tab-pane" id="tab-dn">`;
    if (dn.length) {
        html += `<table class="dt" id="t-dn"><thead><tr>
          <th>Book Code</th><th>Ref No</th><th>BS Date</th><th class="r">Qty</th>
        </tr></thead><tbody>`;
        dn.forEach(r => { const q=parseInt(r.deno_qty)||0; dnTot+=q;
            html += `<tr><td><span class="tag tag-dn">${esc(r.book_code)}</span></td>
                   <td>${esc(r.ref_no||'—')}</td>
                   <td style="font-size:10px">${esc(r.deno_date_nep||'—')}</td>
                   <td class="r" style="color:var(--dn)">${q.toLocaleString()}</td></tr>`; });
        html += `</tbody><tfoot><tr><td colspan="3" style="font-weight:700;text-align:right">Total</td>
          <td class="r" style="font-weight:800;color:var(--dn)">${dnTot.toLocaleString()}</td>
        </tr></tfoot></table>`;
    } else html += `<p style="color:var(--text-muted);padding:16px">No Deno records.</p>`;
    html += `</div>`;

    /* ── Tab: D2M ───────────────────────────────────────────── */
    html += `<div class="tab-pane" id="tab-d2m">`;
    if (d2m.length) {
        html += `<table class="dt" id="t-d2m"><thead><tr>
          <th>Book Code</th><th>D2M No</th><th class="r">Qty</th><th>Status</th>
        </tr></thead><tbody>`;
        d2m.forEach(r => { const q=parseInt(r.d2m_qty)||0; d2mTot+=q;
            const sc={'CLOSE':'sb-d','CANCELLED':'sb-c','DRAFT':'sb-p'}[r.status]||'sb-a';
            html += `<tr><td><span class="tag tag-d2m">${esc(r.book_code)}</span></td>
                   <td>${esc(r.d2m_no||'—')}</td>
                   <td class="r" style="color:var(--d2m)">${q.toLocaleString()}</td>
                   <td><span class="sb ${sc}">${esc(r.status||'—')}</span></td></tr>`; });
        html += `</tbody><tfoot><tr><td colspan="2" style="font-weight:700;text-align:right">Total</td>
          <td class="r" style="font-weight:800;color:var(--d2m)">${d2mTot.toLocaleString()}</td>
          <td></td></tr></tfoot></table>`;
    } else html += `<p style="color:var(--text-muted);padding:16px">No D2M records.</p>`;
    html += `</div>`;

    /* ── Tab: Machine summary ───────────────────────────────── */
    html += `<div class="tab-pane" id="tab-machine">`;
    if (mc.length) {
        const alertMap={}; alts.forEach(a=>alertMap[a.machine]=a);
        html += `<table class="dt" id="t-mc"><thead><tr>
          <th>Machine</th><th>Operator</th><th>Supervisor</th><th>Incharge</th>
          <th>Shift</th><th class="r">FP Qty</th><th class="r">Entries</th>
          <th>Trend (14d)</th><th>Status</th>
        </tr></thead><tbody>`;
        mc.forEach(r => {
            const ma=alertMap[r.machine_name]; let trendHtml='—';
            const mD=mtrend[r.machine_name];
            if (mD) {
                const sd=Object.keys(mD).sort(), vals=sd.map(d=>mD[d]);
                const maxV=Math.max(...vals,1), todayI=sd.indexOf(data.date);
                trendHtml=`<span class="spk-wrap">`+vals.map((v,i)=>{
                    const h=Math.max(2,Math.round((v/maxV)*24));
                    const isT=i===todayI; const fill=isT&&ma?'#ef4444':isT?'#4f46e5':'#93c5fd';
                    return `<span class="spkb" style="height:${h}px;background:${fill}" title="${sd[i]}: ${v.toLocaleString()}"></span>`;
                }).join('')+`</span>`;
            }
            html += `<tr${ma?' class="machine-alert-row"':''}>
              <td><strong>${esc(r.machine_name||'—')}</strong></td>
              <td>${esc(r.operator_name||'—')}</td>
              <td>${esc(r.supervisor_name||'—')}</td>
              <td>${esc(r.incharge_name||'—')}</td>
              <td>${esc(r.shift_name||'—')}</td>
              <td class="r">${parseInt(r.total_qty).toLocaleString()}</td>
              <td class="r">${r.entry_count}</td>
              <td>${trendHtml}</td>
              <td>${ma?`<span class="sb sb-c">⚠ ▼${ma.drop_pct}%</span>`:`<span class="sb sb-a">Normal</span>`}</td>
            </tr>`;
        });
        html += `</tbody></table>`;
    } else html += `<p style="color:var(--text-muted);padding:16px">No machine data.</p>`;
    html += `</div>`;

    /* ── Tab: Daily trend (14d) ─────────────────────────────── */
    html += `<div class="tab-pane" id="tab-trend">`;
    const mkeys = Object.keys(mtrend);
    if (mkeys.length) {
        html += `<p style="font-size:11px;color:var(--text-muted);margin-bottom:10px">
            14-day machine output. Amber = 7-day avg. Red bar = today is below average.
        </p>`;
        mkeys.forEach(mn => {
            const daily=mtrend[mn], sortedD=Object.keys(daily).sort();
            const vals=sortedD.map(d=>daily[d]);
            const chartId='ch_'+mn.replace(/\W+/g,'_')+'_'+Date.now();
            const ao=alts.find(a=>a.machine===mn);
            html += `<div class="trend-wrap">
              <div class="trend-title" style="${ao?'color:var(--danger)':''}">
                ${ao?'⚠ ':''}<strong>${esc(mn)}</strong>
                ${ao?`<span class="alert-drop-badge">▼${ao.drop_pct}%</span>`:''}
              </div>
              <canvas id="${chartId}" height="68"></canvas>
            </div>
            <script>
            (function(){
              const c=document.getElementById('${chartId}'); if(!c) return;
              c.width=c.parentElement.clientWidth-22; c.height=68;
              const W=c.width, H=68, g=c.getContext('2d');
              const dates=${JSON.stringify(sortedD)}, vals=${JSON.stringify(vals)};
              const today='${data.date}', ti=dates.indexOf(today);
              const maxV=Math.max(...vals,1);
              const prevV=vals.slice(0,ti>0?ti:vals.length);
              const avg=prevV.length?prevV.reduce((a,b)=>a+b,0)/prevV.length:0;
              const bw=Math.max(4,(W/vals.length)-3);
              vals.forEach((v,i)=>{
                const x=i*(W/vals.length), h=Math.max(2,(v/maxV)*(H-16));
                const isT=i===ti, isL=isT&&avg>0&&v<avg*0.70;
                g.fillStyle=isL?'#ef4444':isT?'#4f46e5':'#93c5fd';
                g.fillRect(x+2,H-h-12,bw,h);
                g.fillStyle='#94a3b8'; g.font='7px sans-serif';
                g.fillText(dates[i].slice(8),x+2,H-1);
                if(v>0){g.fillStyle=isL?'#ef4444':'#475569'; g.font='bold 8px sans-serif';
                  g.fillText(v>=1000?(v/1000).toFixed(1)+'K':v,x+2,H-h-14);}
              });
              if(avg>0){const ay=H-(avg/maxV)*(H-16)-12;
                g.setLineDash([4,3]);g.strokeStyle='#f59e0b';g.lineWidth=1.5;
                g.beginPath();g.moveTo(0,ay);g.lineTo(W,ay);g.stroke();g.setLineDash([]);
                g.fillStyle='#d97706';g.font='bold 8px sans-serif';
                g.fillText('avg '+(avg>=1000?(avg/1000).toFixed(1)+'K':Math.round(avg)),2,ay-2);}
            })();
            <\/script>`;
        });
    } else html += `<p style="color:var(--text-muted);padding:16px">No trend data.</p>`;
    html += `</div>`;

    /* ── Tab: Alerts ────────────────────────────────────────── */
    if (alts.length) {
        html += `<div class="tab-pane" id="tab-alerts"><div class="alert-banner">
          <div class="alert-banner-title">🚨 ${alts.length} Machine Alert${alts.length>1?'s':''}</div>
          <p style="font-size:11px;color:#7f1d1d;margin-bottom:10px">
            Output on <strong>${data.date}</strong> is &gt;30% below the 7-day rolling average.
          </p>`;
        alts.forEach((a,i)=>{
            html += `<div class="alert-item" style="flex-direction:column;align-items:flex-start;gap:8px">
              <div style="display:flex;align-items:center;gap:9px;width:100%">
                <span class="alert-machine-name">🔴 ${esc(a.machine)}</span>
                <span class="alert-drop-badge">▼${a.drop_pct}% drop</span>
                <span style="margin-left:auto;color:var(--text-muted);font-size:10px">Alert #${i+1}</span>
              </div>
              <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:7px;width:100%">
                <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:7px">
                  <div style="font-size:9px;color:#92400e;font-weight:700;text-transform:uppercase">Today</div>
                  <div style="font-size:18px;font-weight:800;color:var(--danger)">${a.current.toLocaleString()}</div></div>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:7px">
                  <div style="font-size:9px;color:#065f46;font-weight:700;text-transform:uppercase">7-Day Avg</div>
                  <div style="font-size:18px;font-weight:800;color:var(--success)">${a.average.toLocaleString()}</div></div>
                <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:7px">
                  <div style="font-size:9px;color:#991b1b;font-weight:700;text-transform:uppercase">Shortfall</div>
                  <div style="font-size:18px;font-weight:800;color:var(--danger)">${(a.average-a.current).toLocaleString()}</div></div>
              </div>
              <div style="font-size:10px;color:#7f1d1d;background:#fef2f2;border-radius:5px;padding:7px;width:100%">
                <strong>Action:</strong> Investigate <em>${esc(a.machine)}</em>: maintenance, operator, materials.
              </div>
            </div>`;
        });
        html += `</div></div>`;
    }

    document.getElementById('modalBody').innerHTML = html;
    document.getElementById('modalTotals').textContent =
        `FP records: ${fpTot.toLocaleString()} | BP: ${bpTot.toLocaleString()} | Deno: ${dnTot.toLocaleString()} | D2M: ${d2mTot.toLocaleString()}`;
}

function alertBannerHTML(alts) {
    let h=`<div class="alert-banner"><div class="alert-banner-title">🚨 Supervisor Alert</div>`;
    alts.forEach(a=>{ h+=`<div class="alert-item">
      <span class="alert-machine-name">🔴 ${esc(a.machine)}</span>
      <span class="alert-drop-badge">▼${a.drop_pct}%</span>
      <span style="font-size:11px;color:var(--text-muted);flex:1">Today: <strong>${a.current.toLocaleString()}</strong> vs avg: <strong>${a.average.toLocaleString()}</strong></span>
    </div>`; });
    return h+'</div>';
}

function switchTab(btn, tabId) {
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');
    const p=document.getElementById(tabId); if(p) p.classList.add('active');
}

/* ═══════════════════════════════════════════════════════════════════
   TREND ANALYSIS  (BS dates only)
   ═══════════════════════════════════════════════════════════════════ */
document.querySelectorAll('.bucket-btn').forEach(b => {
    b.addEventListener('click', () => {
        document.querySelectorAll('.bucket-btn').forEach(x=>x.classList.remove('active'));
        b.classList.add('active');
        trendBucket = b.dataset.bucket;
        if (trendData) renderTrendCharts();
    });
});

function loadTrendData() {
    document.getElementById('trendOverlay').classList.add('show');
    const fy=document.getElementById('filterFY').value;
    const book=document.getElementById('filterBook').value;
    const df=document.getElementById('dateFromEng').value;
    const dt=document.getElementById('dateToEng').value;
    const p=new URLSearchParams({ajax:'trend_analysis',fiscal_year:fy,book_code:book,date_from:df,date_to:dt});

    fetch(SELF+'?'+p)
        .then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
        .then(j=>{ dbg('trend_analysis',j);
            if(!j.success){ document.getElementById('trendSub').innerHTML=`<span style="color:var(--danger)">Error: ${esc(j.message)}</span>`; return; }
            trendData = j; renderTrendCharts(); })
        .catch(e=>dbg('trend ERR',e.message))
        .finally(()=>document.getElementById('trendOverlay').classList.remove('show'));
}

// Build bucket key (in BS) for a given AD date
function bsBucketKey(adDateStr, bucket) {
    const r = engStrToNep(adDateStr); if (!r) return null;
    switch(bucket) {
        case 'day':     return nepFmt(r);                               // 2083-02-07
        case 'month':   return `${r.year}-${pad(r.month+1)} ${NEP_SHORT[r.month]}`;
        case 'quarter': {
            const q=bsQuarterOf(r.month); const fy=bsFiscalYear(r.year,r.month);
            return `FY${fy}/${fy+1} Q${q}`;
        }
        case 'half': {
            const h=bsHalfOf(r.month); const fy=bsFiscalYear(r.year,r.month);
            return `FY${fy}/${fy+1} H${h}`;
        }
        case 'year': {
            const fy=bsFiscalYear(r.year,r.month);
            return `FY${fy}/${fy+1}`;
        }
    }
    return null;
}
// Sortable key
function bsBucketSort(adDateStr, bucket) {
    const r = engStrToNep(adDateStr); if (!r) return '';
    const fy = bsFiscalYear(r.year, r.month);
    switch(bucket) {
        case 'day':     return nepFmt(r);
        case 'month':   return `${r.year}-${pad(r.month+1)}`;
        case 'quarter': return `${fy}-Q${bsQuarterOf(r.month)}`;
        case 'half':    return `${fy}-H${bsHalfOf(r.month)}`;
        case 'year':    return `${fy}`;
    }
    return '';
}

function renderTrendCharts() {
    if (!trendData) return;
    const {fp_rows, bp_rows, date_from, date_to} = trendData;
    const fromBS = engStrToNep(date_from), toBS = engStrToNep(date_to);
    document.getElementById('trendSub').textContent =
        `Showing ${fromBS?nepFmt(fromBS):'?'} → ${toBS?nepFmt(toBS):'?'} BS  (AD: ${date_from} → ${date_to})`;

    // Group FP by machine + bucket
    const fpByMachine = {};
    const bucketSet = new Map(); // sortKey -> displayLabel
    fp_rows.forEach(r => {
        const k = bsBucketKey(r.day, trendBucket);
        const sk = bsBucketSort(r.day, trendBucket);
        if (!k || !sk) return;
        bucketSet.set(sk, k);
        const mc = r.machine_name || 'Unknown';
        if (!fpByMachine[mc]) fpByMachine[mc] = {};
        fpByMachine[mc][sk] = (fpByMachine[mc][sk] || 0) + parseInt(r.fp_qty || 0);
    });

    const sortedKeys = Array.from(bucketSet.keys()).sort();
    const labels = sortedKeys.map(sk => bucketSet.get(sk));

    // Datasets
    const palette = ['#3b82f6','#10b981','#8b5cf6','#f59e0b','#ef4444','#06b6d4','#ec4899','#84cc16','#6366f1','#14b8a6'];
    const datasets = Object.keys(fpByMachine).sort().map((mc,i) => ({
        label: mc,
        data: sortedKeys.map(sk => fpByMachine[mc][sk] || 0),
        borderColor: palette[i % palette.length],
        backgroundColor: palette[i % palette.length] + '22',
        borderWidth: 2, tension: 0.3, fill: false,
        pointRadius: 3, pointHoverRadius: 5,
    }));

    if (trendChart) trendChart.destroy();
    trendChart = new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive:true, maintainAspectRatio:false,
            plugins: {
                legend:{position:'top',labels:{font:{size:11},boxWidth:12}},
                tooltip:{callbacks:{
                    label:c=>`${c.dataset.label}: ${c.parsed.y.toLocaleString()}`,
                    title:items=>items[0].label
                }}
            },
            scales: {
                x: { ticks: { font: { size: 10 } }, title:{display:true,text:'Nepali ('+trendBucket+')',font:{size:11,weight:'bold'}} },
                y: { beginAtZero:true, ticks:{callback:v=>fmt(v),font:{size:10}} }
            }
        }
    });

    // FP vs BP chart (totals per bucket)
    const fpTot={}, bpTot={};
    fp_rows.forEach(r => {
        const sk=bsBucketSort(r.day,trendBucket); if(!sk) return;
        fpTot[sk] = (fpTot[sk]||0) + parseInt(r.fp_qty||0);
    });
    bp_rows.forEach(r => {
        const sk=bsBucketSort(r.day,trendBucket); if(!sk) return;
        bucketSet.set(sk, bsBucketKey(r.day,trendBucket));
        bpTot[sk] = (bpTot[sk]||0) + parseInt(r.bp_qty||0);
    });
    const sk2 = Array.from(bucketSet.keys()).sort();
    const lbl2 = sk2.map(sk => bucketSet.get(sk));

    if (fpBpChart) fpBpChart.destroy();
    fpBpChart = new Chart(document.getElementById('fpBpChart'), {
        type: 'bar',
        data: {
            labels: lbl2,
            datasets: [
                { label:'FP qty (intermediate)', data:sk2.map(k=>fpTot[k]||0),
                  backgroundColor:'#3b82f6' },
                { label:'BP qty (final books)', data:sk2.map(k=>bpTot[k]||0),
                  backgroundColor:'#10b981' },
            ]
        },
        options: {
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{position:'top',labels:{font:{size:11}}},
                      tooltip:{callbacks:{label:c=>`${c.dataset.label}: ${c.parsed.y.toLocaleString()}`}} },
            scales: {
                x:{ticks:{font:{size:10}},title:{display:true,text:'Nepali ('+trendBucket+')',font:{size:11,weight:'bold'}}},
                y:{beginAtZero:true,ticks:{callback:v=>fmt(v),font:{size:10}}}
            }
        }
    });
}

/* ═══════════════════════════════════════════════════════════════════
   MANPOWER VIEW
   ═══════════════════════════════════════════════════════════════════ */
function loadManpower() {
    document.getElementById('manpowerOverlay').classList.add('show');
    const fy=document.getElementById('filterFY').value;
    const book=document.getElementById('filterBook').value;
    const df=document.getElementById('dateFromEng').value;
    const dt=document.getElementById('dateToEng').value;
    const p=new URLSearchParams({ajax:'manpower',fiscal_year:fy,book_code:book,date_from:df,date_to:dt});

    fetch(SELF+'?'+p)
        .then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
        .then(j=>{ dbg('manpower',j);
            if(!j.success){ document.getElementById('manpowerTable').innerHTML=`<div class="alert alert-danger">${esc(j.message)}</div>`; return; }
            renderManpower(j); })
        .catch(e=>dbg('manpower ERR',e.message))
        .finally(()=>document.getElementById('manpowerOverlay').classList.remove('show'));
}

function renderManpower(data) {
    const recs = data.records || [];

    // ── Per-shift summary ─────────────────────────────────────
    const shifts = {};
    recs.forEach(r => {
        const sh = r.shift_name || '—';
        if (!shifts[sh]) shifts[sh] = {
            shift:sh, total_qty:0, entries:0,
            operators:new Set(), machines:new Set(), dates:new Set()
        };
        shifts[sh].total_qty += parseInt(r.qty)||0;
        shifts[sh].entries++;
        if (r.operator_name && r.operator_name!=='—') shifts[sh].operators.add(r.operator_name);
        if (r.machine_name && r.machine_name!=='—')   shifts[sh].machines.add(r.machine_name);
        if (r.work_date) shifts[sh].dates.add(r.work_date);
    });

    let shHtml = '';
    if (Object.keys(shifts).length) {
        shHtml = `<table class="dt"><thead><tr>
          <th>Shift</th><th class="r">Total FP Qty</th><th class="r">Entries</th>
          <th class="r">Shift-days</th><th class="r">Qty / day</th>
          <th class="r">Operators</th><th>Operator names</th>
          <th class="r">Machines</th><th>Machine names</th>
        </tr></thead><tbody>`;
        Object.values(shifts).forEach(s => {
            const days = s.dates.size;
            const perDay = days>0 ? Math.round(s.total_qty/days) : 0;
            shHtml += `<tr>
              <td><strong>${esc(s.shift)}</strong></td>
              <td class="r">${s.total_qty.toLocaleString()}</td>
              <td class="r">${s.entries}</td>
              <td class="r">${days}</td>
              <td class="r" style="color:var(--accent);font-weight:800">${perDay.toLocaleString()}</td>
              <td class="r">${s.operators.size}</td>
              <td style="font-size:10px;max-width:200px;word-wrap:break-word">${esc(Array.from(s.operators).join(', '))||'—'}</td>
              <td class="r">${s.machines.size}</td>
              <td style="font-size:10px;max-width:160px;word-wrap:break-word">${esc(Array.from(s.machines).join(', '))||'—'}</td>
            </tr>`;
        });
        shHtml += `</tbody></table>`;
    } else shHtml = `<p style="color:var(--text-muted);padding:14px">No records in this range.</p>`;
    document.getElementById('shiftSummary').innerHTML = shHtml;

    // ── Per-record table with AD + BS dates ──────────────────
    if (!recs.length) {
        document.getElementById('manpowerTable').innerHTML =
            `<p style="color:var(--text-muted);padding:14px">No records.</p>`;
        return;
    }
    let html = `<table class="dt" id="t-manpower"><thead><tr>
      <th>AD Date</th><th>BS Date</th><th>JT Code</th><th>Book</th>
      <th class="r">Qty</th><th>Machine</th><th>Shift</th>
      <th>Operator</th><th>Supervisor</th><th>Incharge</th>
    </tr></thead><tbody>`;
    recs.forEach(r => {
        // BS date — prefer DB-stored, else convert from AD
        let bs = r.work_date_nep || '';
        if (!bs && r.work_date) {
            const np = engStrToNep(r.work_date);
            if (np) bs = nepFmt(np);
        }
        html += `<tr>
          <td style="font-size:10px">${esc(r.work_date||'—')}</td>
          <td style="font-size:10px;color:#166534;font-weight:700">${esc(bs||'—')}</td>
          <td><span class="tag tag-jt">${esc(r.job_ticket_code)}</span></td>
          <td><span class="tag tag-fp">${esc(r.book_code)}</span></td>
          <td class="r" style="color:var(--fp)">${parseInt(r.qty||0).toLocaleString()}</td>
          <td>${esc(r.machine_name)}</td>
          <td>${esc(r.shift_name)}</td>
          <td>${esc(r.operator_name)}</td>
          <td>${esc(r.supervisor_name)}</td>
          <td>${esc(r.incharge_name)}</td>
        </tr>`;
    });
    html += `</tbody></table>`;
    document.getElementById('manpowerTable').innerHTML = html;
}

/* ═══════════════════════════════════════════════════════════════════
   EXPORTS
   ═══════════════════════════════════════════════════════════════════ */
function exportDayExcel() {
    const wb = XLSX.utils.book_new();
    [['t-fp','FP'],['t-bp','BP'],['t-dn','Deno'],['t-d2m','D2M'],['t-mc','Machine']].forEach(([id,n])=>{
        const t=document.getElementById(id); if(t) XLSX.utils.book_append_sheet(wb,XLSX.utils.table_to_sheet(t),n);
    });
    XLSX.writeFile(wb, `production_${activeDate}.xlsx`);
}
function exportDayPdf() {
    const w = window.open('','_blank');
    w.document.write(`<!DOCTYPE html><html><head><title>Production ${activeDate}</title>
    <style>body{font-family:sans-serif;font-size:11px}table{width:100%;border-collapse:collapse;margin:0 0 12px}
    th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}th{background:#f1f5f9;font-weight:700}
    .r{text-align:right}.tag{padding:1px 4px;border-radius:3px;background:#dbeafe}
    </style></head><body>
    <h2 style="margin:0 0 4px">Production Report — ${activeDate}</h2>
    <p style="color:#64748b;font-size:10px;margin:0 0 12px">${document.getElementById('modalSub').textContent}</p>
    ${['tab-fp','tab-bp','tab-dn','tab-d2m'].map(id=>{ const el=document.getElementById(id); return el?el.innerHTML:''; }).join('')}
    </body></html>`);
    w.document.close(); w.focus(); w.print();
}
function exportMonthExcel() {
    const rows=[['Date (AD)','Date (BS)','Nepali Month','Book Codes','FP','BP','Deno','D2M','JT','Alerts']];
    Object.entries(calData).sort().forEach(([d,v])=>{
        const r=engStrToNep(d);
        const bs=r?nepFmt(r):'';
        const mo=r?NEP_FULL[r.month]+' '+r.year:'';
        rows.push([d,bs,mo,v.book_codes||'',v.fp,v.bp,v.deno,v.d2m,v.jt,
                   (v.alerts||[]).map(a=>a.machine+'▼'+a.drop_pct+'%').join('; ')]);
    });
    const wb=XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb,XLSX.utils.aoa_to_sheet(rows),'Monthly');
    XLSX.writeFile(wb,`calendar_${CY}_${pad(CM)}.xlsx`);
}

/* ═══════════════════════════════════════════════════════════════════
   NAV
   ═══════════════════════════════════════════════════════════════════ */
function prevM(){ CM--; if(CM<1){CM=12;CY--;} loadMonth(); }
function nextM(){ CM++; if(CM>12){CM=1;CY++;} loadMonth(); }
function goToday(){ const n=new Date(); CY=n.getFullYear(); CM=n.getMonth()+1; loadMonth(); }

document.getElementById('prevBtn').addEventListener('click',prevM);
document.getElementById('nextBtn').addEventListener('click',nextM);
document.getElementById('todayBtn').addEventListener('click',goToday);
document.getElementById('applyBtn').addEventListener('click', () => {
    loadMonth();
    if (currentView === 'view-trends')   loadTrendData();
    if (currentView === 'view-manpower') loadManpower();
});
document.getElementById('resetBtn').addEventListener('click', () => {
    ['filterFY','filterBook','dateFromEng','dateToEng','dateFromNep','dateToNep']
        .forEach(id=>document.getElementById(id).value='');
    rangeFrom=''; rangeTo=''; document.getElementById('rangeInfo').textContent='';
    goToday();
});
document.getElementById('jumpMonth').addEventListener('change',function(){
    const p=this.value.split('-'); if(p.length===2){CY=+p[0];CM=+p[1];loadMonth();}
});
document.getElementById('exportMonthBtn').addEventListener('click',exportMonthExcel);
document.getElementById('xlBtn').addEventListener('click',exportDayExcel);
document.getElementById('pdfBtn').addEventListener('click',exportDayPdf);
document.addEventListener('keydown',e=>{
    if(e.target.tagName==='INPUT'||e.target.tagName==='SELECT') return;
    if(currentView!=='view-calendar') return;
    if(e.key==='ArrowLeft') prevM();
    if(e.key==='ArrowRight') nextM();
    if(e.key==='t'||e.key==='T') goToday();
});

/* ═══════════════════════════════════════════════════════════════════
   DEBUG
   ═══════════════════════════════════════════════════════════════════ */
function dbg(label, data) {
    const el=document.getElementById('debugLog'); if(!el) return;
    const ts=new Date().toLocaleTimeString();
    const val=typeof data==='object'?JSON.stringify(data,null,2).slice(0,600):String(data);
    el.innerHTML+=`<div style="margin-bottom:6px"><span style="color:#60a5fa">[${ts}]</span> <span style="color:#f8fafc">${esc(label)}</span><pre style="color:#94a3b8;margin:2px 0 0;white-space:pre-wrap;font-size:9px">${esc(val)}</pre></div>`;
    el.scrollTop=el.scrollHeight;
}
document.getElementById('debugToggle').addEventListener('click',()=>{
    const p=document.getElementById('debugPanel'); p.classList.toggle('show');
    if(p.classList.contains('show')){
        dbg('NepaliDate loaded', _ND?'✅ YES':'❌ NO');
        if(_ND){ const r=engToNep(2026,5,21); dbg('Test: 2026-05-21 → BS', r?nepFmt(r)+' ('+NEP_FULL[r.month]+' '+r.date+')':null); }
        // Test BS fiscal quarter
        if(_ND){
            const tests = [[2083,0],[2083,3],[2083,6],[2083,9]];
            tests.forEach(([y,m])=> dbg(`BS ${y}/${NEP_SHORT[m]} → Q${bsQuarterOf(m)} H${bsHalfOf(m)} FY${bsFiscalYear(y,m)}`, ''));
        }
        const n=new Date(), td=`${n.getFullYear()}-${pad(n.getMonth()+1)}-${pad(n.getDate())}`;
        const fy=document.getElementById('filterFY').value;
        ['day_detail','month_data'].forEach(ep => {
            const params={ajax:ep,fiscal_year:fy};
            if(ep==='day_detail') params.date=td;
            else { params.year=n.getFullYear(); params.month=n.getMonth()+1; }
            fetch(SELF+'?'+new URLSearchParams(params)).then(r=>r.json()).then(j=>{
                dbg(`${ep} → success=${j.success}`, j.success?'✅':'❌ '+j.message);
            }).catch(e=>dbg(ep+' FAILED',e.message));
        });
    }
});

// ── Boot ──
loadMonth();
</script>
</body>
</html>
<?php ob_end_flush(); ?>