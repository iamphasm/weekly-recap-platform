<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

/** Send an HTML email. Returns true on success. Uses PHPMailer/SMTP, falls back to mail(). */
function send_mail(string $to, string $subject, string $html, string $text): bool
{
    $m = cfg('mail', []);
    $fromEmail = $m['from_email'] ?? 'noreply@localhost';
    $fromName = $m['from_name'] ?? cfg('site_name', 'Weekly Recap');

    try {
        if (class_exists(PHPMailer::class) && !empty($m['smtp_host'])) {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $m['smtp_host'];
            $mail->Port = (int)($m['smtp_port'] ?? 587);
            $mail->SMTPSecure = $m['smtp_secure'] ?? '';
            if (!empty($m['smtp_user'])) {
                $mail->SMTPAuth = true;
                $mail->Username = $m['smtp_user'];
                $mail->Password = $m['smtp_pass'] ?? '';
            }
            $mail->Timeout = 10; // PHPMailer's default is 300 s, longer than PHP-FPM/proxy limits, so a dead SMTP host hung the page
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text;
            $mail->send();
            return true;
        }

        $boundary = bin2hex(random_bytes(12));
        $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$fromEmail>\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$text\r\n"
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n--$boundary--";
        return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    } catch (Throwable $e) {
        error_log('send_mail failed for ' . $to . ': ' . $e->getMessage());
        return false;
    }
}

function email_shell(string $title, string $inner): string
{
    return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#1f2937">'
        . '<h2 style="margin-bottom:4px">' . e($title) . '</h2>' . $inner
        . '<p style="color:#6b7280;font-size:12px;margin-top:32px">Sent by ' . e(cfg('site_name')) . ' - ' . e(app_url()) . '</p></div>';
}

function trend_text(int $now, ?int $before, string $unit = 'last recap'): string
{
    if ($before === null) {
        return '';
    }
    $d = $now - $before;
    if ($d === 0) {
        return "Same as $unit ($before%).";
    }
    return ($d > 0 ? "Up $d points" : 'Down ' . abs($d) . ' points') . " from $unit ($before%).";
}

/** Percent score of the student's most recent attempt before $before (datetime), or null. */
function previous_percent(int $studentId, string $before): ?int
{
    $r = q('SELECT score, max_score FROM attempts WHERE student_id = ? AND submitted_at < ? ORDER BY submitted_at DESC LIMIT 1',
        [$studentId, $before])->fetch();
    return $r ? pct((int)$r['score'], (int)$r['max_score']) : null;
}

function send_weekly_debrief(int $attemptId): bool
{
    $a = q('SELECT a.*, s.email, t.title FROM attempts a JOIN students s ON s.id=a.student_id JOIN tests t ON t.id=a.test_id WHERE a.id = ?', [$attemptId])->fetch();
    if (!$a) {
        return false;
    }
    $questions = load_questions((int)$a['test_id']);
    $answers = load_answers($attemptId);
    $p = pct((int)$a['score'], (int)$a['max_score']);
    $trend = trend_text($p, previous_percent((int)$a['student_id'], $a['submitted_at']));

    $rows = '';
    $text = "{$a['title']}\nScore: {$a['score']}/{$a['max_score']} ($p%)\n$trend\n\n";
    foreach ($questions as $i => $qq) {
        $ans = $answers[$qq['id']] ?? ['response' => null, 'is_correct' => false];
        $ok = $ans['is_correct'];
        $mine = describe_response($qq, $ans['response']);
        $right = describe_correct($qq);
        $rows .= '<tr><td style="padding:8px;border-bottom:1px solid #e5e7eb;vertical-align:top">'
            . '<b>' . ($i + 1) . '. ' . nl2br(e($qq['prompt'])) . '</b><br>'
            . 'Your answer: <span style="color:' . ($ok ? '#15803d' : '#b91c1c') . '">' . e($mine) . '</span>'
            . ($ok ? '' : '<br>Correct answer: <b>' . e($right) . '</b>')
            . ($qq['explanation'] ? '<br><i style="color:#6b7280">' . nl2br(e($qq['explanation'])) . '</i>' : '')
            . '</td><td style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:20px;vertical-align:top">' . ($ok ? '&#10003;' : '&#10007;') . '</td></tr>';
        $text .= ($i + 1) . ". {$qq['prompt']}\n   Your answer: $mine\n" . ($ok ? "   Correct\n" : "   Correct answer: $right\n") . "\n";
    }
    $html = email_shell('Weekly recap: ' . $a['title'],
        '<p style="font-size:18px">You scored <b>' . (int)$a['score'] . ' / ' . (int)$a['max_score'] . ' (' . $p . '%)</b>.</p>'
        . ($trend ? '<p>' . e($trend) . '</p>' : '')
        . '<table style="width:100%;border-collapse:collapse">' . $rows . '</table>');

    $ok = send_mail($a['email'], 'Your weekly recap: ' . $a['title'], $html, $text);
    if ($ok) {
        q('UPDATE attempts SET email_sent_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $attemptId]);
    }
    return $ok;
}

/** Monthly recap for $month ('YYYY-MM'). Returns false if the student has no attempts that month. */
function send_monthly_recap(array $student, string $month): bool
{
    $start = $month . '-01 00:00:00';
    $end = date('Y-m-d 00:00:00', strtotime($start . ' +1 month'));
    $rows = q('SELECT a.*, t.title FROM attempts a JOIN tests t ON t.id=a.test_id
               WHERE a.student_id = ? AND a.submitted_at >= ? AND a.submitted_at < ? ORDER BY a.submitted_at',
        [$student['id'], $start, $end])->fetchAll();
    if (!$rows) {
        return false;
    }

    $sum = 0;
    $table = '';
    $text = '';
    foreach ($rows as $r) {
        $p = pct((int)$r['score'], (int)$r['max_score']);
        $sum += $p;
        $table .= '<tr><td style="padding:6px;border-bottom:1px solid #e5e7eb">' . e($r['title']) . '</td>'
            . '<td style="padding:6px;border-bottom:1px solid #e5e7eb">' . e(date('j M', strtotime($r['submitted_at']))) . '</td>'
            . '<td style="padding:6px;border-bottom:1px solid #e5e7eb"><b>' . $r['score'] . '/' . $r['max_score'] . ' (' . $p . '%)</b></td></tr>';
        $text .= "{$r['title']} - {$r['score']}/{$r['max_score']} ($p%)\n";
    }
    $avg = (int)round($sum / count($rows));

    $prevStart = date('Y-m-01 00:00:00', strtotime($start . ' -1 month'));
    $prev = q('SELECT score, max_score FROM attempts WHERE student_id = ? AND submitted_at >= ? AND submitted_at < ?',
        [$student['id'], $prevStart, $start])->fetchAll();
    $prevAvg = null;
    if ($prev) {
        $prevAvg = (int)round(array_sum(array_map(fn($x) => pct((int)$x['score'], (int)$x['max_score']), $prev)) / count($prev));
    }
    $trend = trend_text($avg, $prevAvg, 'last month');

    // Missed tests: published tests whose window ended in the month without an attempt from this student.
    $missed = q('SELECT t.title FROM tests t WHERE t.published = 1 AND t.closes_at >= ? AND t.closes_at < ?
                 AND NOT EXISTS (SELECT 1 FROM attempts a WHERE a.test_id = t.id AND a.student_id = ?)',
        [$start, $end, $student['id']])->fetchAll(PDO::FETCH_COLUMN);

    $label = date('F Y', strtotime($start));
    $html = email_shell("Monthly recap: $label",
        '<p style="font-size:18px">You completed <b>' . count($rows) . '</b> recap(s) with an average of <b>' . $avg . '%</b>.</p>'
        . ($trend ? '<p>' . e($trend) . '</p>' : '')
        . '<table style="width:100%;border-collapse:collapse">' . $table . '</table>'
        . ($missed ? '<p style="color:#b45309">Not completed: ' . e(implode(', ', $missed)) . '</p>' : ''));
    $text = "Monthly recap: $label\nCompleted " . count($rows) . " recap(s), average $avg%.\n$trend\n\n$text"
        . ($missed ? "\nNot completed: " . implode(', ', $missed) . "\n" : '');

    return send_mail($student['email'], "Your monthly recap: $label", $html, $text);
}

const INVITE_CODE_TTL_MIN = 1440; // a code in an invitation email may be read hours later

/**
 * Teacher added a student: email them a sign-in code and a link to the code page.
 * Returns 'sent', 'recent' (a code went out a moment ago, nothing sent) or 'failed'.
 */
function send_student_invite(string $email, string $name): string
{
    [$status, $code] = create_login_code($email, INVITE_CODE_TTL_MIN);
    if ($status !== 'sent') {
        return $status === 'cooldown' ? 'recent' : 'failed';
    }
    $site = site_name();
    $link = app_url() . '/index.php?email=' . rawurlencode($email);
    $hello = $name !== '' ? 'Hi ' . e($name) . ',' : 'Hi,';
    $html = email_shell("You have been added to $site",
        "<p>$hello</p><p>Your teacher added you to <b>" . e($site) . '</b>. Your login code is:</p>'
        . '<p style="font-size:32px;letter-spacing:6px;font-weight:bold">' . $code . '</p>'
        . '<p><a href="' . e($link) . '">Open the login page</a> and enter the code. It is valid for 24 hours; '
        . 'after that you can request a new one on the same page with your school email.</p>');
    $text = ($name !== '' ? "Hi $name,\n\n" : '') . "Your teacher added you to $site.\nYour login code: $code\n\nEnter it here: $link\n"
        . "It is valid for 24 hours; after that you can request a new one with your school email.";
    if (send_mail($email, "You have been added to $site", $html, $text)) {
        return 'sent';
    }
    revoke_login_code($email);
    return 'failed';
}
