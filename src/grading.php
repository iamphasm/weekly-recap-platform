<?php
declare(strict_types=1);

const QUESTION_TYPES = [
    'single' => 'Multiple choice (one answer)',
    'multi'  => 'Multiple choice (several answers)',
    'tf'     => 'True / False',
    'text'   => 'Short text answer',
    'match'  => 'Matching (items to descriptions)',
];

/** Questions of a test, each with an 'options' array, ordered. */
function load_questions(int $testId): array
{
    $qs = q('SELECT * FROM questions WHERE test_id = ? ORDER BY position, id', [$testId])->fetchAll();
    if (!$qs) {
        return [];
    }
    $ids = array_column($qs, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $opts = q("SELECT * FROM options WHERE question_id IN ($in) ORDER BY position, id", $ids)->fetchAll();
    $by = [];
    foreach ($opts as $o) {
        $by[$o['question_id']][] = $o;
    }
    foreach ($qs as &$qq) {
        $qq['options'] = $by[$qq['id']] ?? [];
    }
    return $qs;
}

/**
 * Opaque stand-in for an option id in a matching question's dropdowns. The real id of a description equals the id of
 * the item it belongs to, so putting it in the page would give the answers away. Resolved again in parse_response().
 */
function match_token(int $questionId, int $optionId): string
{
    $_SESSION['match_salt'] ??= bin2hex(random_bytes(16));
    return substr(hash_hmac('sha256', $questionId . '|' . $optionId, $_SESSION['match_salt']), 0, 20);
}

/** Descriptions of a matching question in this student's own stable order (differs per student, same on reload). */
function shuffled_pairs(array $question, int $studentId): array
{
    $opts = $question['options'];
    usort($opts, fn($a, $b) => strcmp(md5($studentId . '-' . $a['id']), md5($studentId . '-' . $b['id'])));
    return $opts;
}

function norm_text(string $s): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
}

/** Raw form input for one question -> normalized response (int | int[] | string | null). */
function parse_response(array $question, $raw)
{
    $valid = array_map('intval', array_column($question['options'], 'id'));
    switch ($question['type']) {
        case 'single':
        case 'tf':
            $v = is_scalar($raw) ? (int)$raw : 0;
            return in_array($v, $valid, true) ? $v : null;
        case 'multi':
            $out = [];
            foreach ((array)$raw as $r) {
                if (is_scalar($r) && in_array((int)$r, $valid, true)) {
                    $out[] = (int)$r;
                }
            }
            $out = array_values(array_unique($out));
            sort($out);
            return $out ?: null;
        case 'match': // [left option id => id of the option whose description was picked]
            $byToken = [];
            foreach ($question['options'] as $o) {
                $byToken[match_token((int)$question['id'], (int)$o['id'])] = (int)$o['id'];
            }
            $out = [];
            foreach ($question['options'] as $o) {
                $t = (is_array($raw) && isset($raw[$o['id']]) && is_scalar($raw[$o['id']])) ? (string)$raw[$o['id']] : '';
                if (isset($byToken[$t])) {
                    $out[(int)$o['id']] = $byToken[$t];
                }
            }
            return $out ?: null;
        default:
            $s = is_scalar($raw) ? trim((string)$raw) : '';
            return $s === '' ? null : mb_substr($s, 0, 500);
    }
}

function is_response_correct(array $question, $response): bool
{
    if ($response === null) {
        return false;
    }
    if ($question['type'] === 'match') { // every item needs a description equal to its own (all or nothing)
        $pair = [];
        foreach ($question['options'] as $o) {
            $pair[(int)$o['id']] = norm_text((string)$o['pair_label']);
        }
        foreach ($pair as $id => $text) {
            $picked = (int)($response[$id] ?? 0);
            if (!isset($pair[$picked]) || $pair[$picked] !== $text) {
                return false;
            }
        }
        return true;
    }
    $correct = [];
    foreach ($question['options'] as $o) {
        if ($o['is_correct']) {
            $correct[] = (int)$o['id'];
        }
    }
    switch ($question['type']) {
        case 'single':
        case 'tf':
            return count($correct) === 1 && $response === $correct[0];
        case 'multi':
            sort($correct);
            return $correct && $response === $correct;
        default:
            foreach ($question['options'] as $o) {
                if ($o['is_correct'] && norm_text($o['label']) === norm_text((string)$response)) {
                    return true;
                }
            }
            return false;
    }
}

/** Human-readable version of a stored response. */
function describe_response(array $question, $response): string
{
    if ($response === null || $response === []) {
        return '(no answer)';
    }
    if ($question['type'] === 'text') {
        return (string)$response;
    }
    if ($question['type'] === 'match') {
        $desc = array_column($question['options'], 'pair_label', 'id');
        $parts = [];
        foreach ($question['options'] as $o) {
            $parts[] = $o['label'] . ' → ' . ($desc[(int)($response[(int)$o['id']] ?? 0)] ?? '(no answer)');
        }
        return implode('; ', $parts);
    }
    $labels = [];
    foreach ($question['options'] as $o) {
        if (in_array((int)$o['id'], (array)$response, true)) {
            $labels[] = $o['label'];
        }
    }
    return $labels ? implode(', ', $labels) : '(no answer)';
}

function describe_correct(array $question): string
{
    if ($question['type'] === 'match') {
        return implode('; ', array_map(fn($o) => $o['label'] . ' → ' . $o['pair_label'], $question['options']));
    }
    $labels = [];
    foreach ($question['options'] as $o) {
        if ($o['is_correct']) {
            $labels[] = $o['label'];
        }
    }
    return implode($question['type'] === 'text' ? ' / ' : ', ', $labels);
}

/**
 * Stored answers for an attempt, keyed by question id: ['response' => decoded, 'is_correct' => bool]
 */
function load_answers(int $attemptId): array
{
    $out = [];
    foreach (q('SELECT * FROM answers WHERE attempt_id = ?', [$attemptId])->fetchAll() as $a) {
        $out[$a['question_id']] = [
            'response' => $a['response'] === null ? null : json_decode($a['response'], true),
            'is_correct' => (bool)$a['is_correct'],
        ];
    }
    return $out;
}

/** Create question + options from teacher form input. Returns error string or null. */
function save_question(int $testId, ?int $questionId, array $in): ?string
{
    $type = $in['type'] ?? '';
    $prompt = trim((string)($in['prompt'] ?? ''));
    $explanation = trim((string)($in['explanation'] ?? ''));
    if (!isset(QUESTION_TYPES[$type])) {
        return 'Pick a question type.';
    }
    if ($prompt === '') {
        return 'The question text is required.';
    }

    $options = []; // [label, correct, pair_label]
    if ($type === 'match') {
        foreach (preg_split('/\R/', (string)($in['options'] ?? '')) as $line) {
            if (trim($line) === '') {
                continue;
            }
            [$left, $right] = array_pad(explode('=', $line, 2), 2, '');
            $left = trim($left);
            $right = trim($right);
            if ($left === '' || $right === '') {
                return 'Write each pair as: item = description (both sides are required).';
            }
            $options[] = [mb_substr($left, 0, 500), true, mb_substr($right, 0, 500)];
        }
        if (count($options) < 2) {
            return 'Enter at least two pairs.';
        }
    } elseif ($type === 'tf') {
        $answer = ($in['tf_answer'] ?? 'true') === 'false' ? 'false' : 'true';
        $options = [['True', $answer === 'true'], ['False', $answer === 'false']];
    } else {
        foreach (preg_split('/\R/', (string)($in['options'] ?? '')) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $correct = false;
            if ($line[0] === '*') {
                $correct = true;
                $line = trim(substr($line, 1));
            }
            if ($line !== '') {
                $options[] = [mb_substr($line, 0, 500), $correct];
            }
        }
        $nCorrect = count(array_filter($options, fn($o) => $o[1]));
        if ($type === 'text') {
            $options = array_values(array_filter($options, fn($o) => true));
            if (!$options) {
                return 'Enter at least one accepted answer.';
            }
            $options = array_map(fn($o) => [$o[0], true], $options); // every line is accepted
        } else {
            if (count($options) < 2) {
                return 'Enter at least two options.';
            }
            if ($type === 'single' && $nCorrect !== 1) {
                return 'Mark exactly one option as correct by starting the line with *.';
            }
            if ($type === 'multi' && $nCorrect < 1) {
                return 'Mark at least one option as correct by starting the line with *.';
            }
        }
    }

    $leftTitle = $rightTitle = null;
    if ($type === 'match') {
        $leftTitle = mb_substr(trim((string)($in['left_title'] ?? '')), 0, 80) ?: null;
        $rightTitle = mb_substr(trim((string)($in['right_title'] ?? '')), 0, 80) ?: null;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($questionId) {
            q('UPDATE questions SET type=?, prompt=?, explanation=?, left_title=?, right_title=? WHERE id=? AND test_id=?',
                [$type, $prompt, $explanation ?: null, $leftTitle, $rightTitle, $questionId, $testId]);
            q('DELETE FROM options WHERE question_id = ?', [$questionId]);
        } else {
            $pos = (int)q('SELECT COALESCE(MAX(position),0)+1 FROM questions WHERE test_id = ?', [$testId])->fetchColumn();
            q('INSERT INTO questions (test_id, position, type, prompt, explanation, left_title, right_title) VALUES (?,?,?,?,?,?,?)',
                [$testId, $pos, $type, $prompt, $explanation ?: null, $leftTitle, $rightTitle]);
            $questionId = (int)$pdo->lastInsertId();
        }
        foreach ($options as $i => [$label, $correct, $pair]) {
            q('INSERT INTO options (question_id, position, label, pair_label, is_correct) VALUES (?,?,?,?,?)',
                [$questionId, $i + 1, $label, $pair ?? null, $correct ? 1 : 0]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return null;
}
