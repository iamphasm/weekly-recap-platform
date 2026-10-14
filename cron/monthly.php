<?php
// Weekly Recap Platform - https://github.com/iamphasm/weekly-recap-platform
// Created by Kristian Daae-Johansen / IamPhasm
// Stack: PHP + MySQL
// Sends the monthly recap email to every student with activity in the month.
// Usage:  php cron/monthly.php            -> previous month (run on the 1st)
//         php cron/monthly.php 2026-10    -> a specific month (safe to re-run; already-sent students are skipped)
// Crontab: 0 7 1 * * /usr/bin/php /path/to/recap/cron/monthly.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/../src/bootstrap.php';

$month = $argv[1] ?? date('Y-m', strtotime('first day of last month'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    fwrite(STDERR, "Month must be YYYY-MM\n");
    exit(1);
}

$students = q('SELECT DISTINCT s.* FROM students s JOIN attempts a ON a.student_id = s.id
               WHERE a.submitted_at >= ? AND a.submitted_at < ?',
    [$month . '-01 00:00:00', date('Y-m-d 00:00:00', strtotime($month . '-01 +1 month'))])->fetchAll();

$sent = $failed = $skipped = 0;
foreach ($students as $s) {
    if (q('SELECT 1 FROM monthly_log WHERE student_id = ? AND month = ?', [$s['id'], $month])->fetch()) {
        $skipped++;
        continue;
    }
    if (send_monthly_recap($s, $month)) {
        q('INSERT INTO monthly_log (student_id, month, sent_at) VALUES (?,?,?)', [$s['id'], $month, date('Y-m-d H:i:s')]);
        $sent++;
    } else {
        $failed++;
        fwrite(STDERR, "Failed: {$s['email']}\n");
    }
}
echo "Month $month: sent $sent, already sent $skipped, failed $failed\n";
exit($failed ? 2 : 0);
