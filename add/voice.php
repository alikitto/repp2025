<?php
require_once __DIR__ . '/../profile/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
csrf_check_json();

header('Content-Type: application/json; charset=utf-8');

if (teacher_id() <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$text = trim(preg_replace('/\s+/u', ' ', (string)($_POST['text'] ?? '')));
if ($text === '' || mb_strlen($text) > 240) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty']);
    exit;
}

$parsed = voice_parse($text);
$tid = teacher_id();
$sql = 'SELECT s.user_id, s.name, s.lastname, COALESCE(s.money,0) AS money, s.pay_mode, '
    . student_balance_expr() . ' AS balance FROM stud s '
    . student_balance_joins()
    . ' WHERE s.teacher_id=? AND s.archived=0';
$st = $con->prepare($sql);
$st->bind_param('i', $tid);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$q = voice_key($parsed['name']);
$scored = [];
foreach ($rows as $row) {
    $name = trim((string)$row['name']);
    $last = trim((string)$row['lastname']);
    $fio = trim($last . ' ' . $name);
    $score = 0;
    foreach ([$name, $last, $fio] as $part) {
        $score = max($score, voice_score($q, voice_key($part)));
    }
    if ($score < 60) continue;
    $money = (float)$row['money'];
    $lessons = (int)$parsed['lessons'];
    $amount = (float)$parsed['amount'];
    if ($lessons <= 0 && $amount > 0 && $money > 0) {
        $lessons = (int)round($amount / $money);
    }
    if ($lessons <= 0) $lessons = current_pack_lessons();
    $lessons = max(1, min(40, $lessons));
    if ($amount <= 0) $amount = $money * $lessons;
    $bal = (int)$row['balance'];
    $mode = student_pay_mode($row['pay_mode'] ?? 'prepaid');
    $scored[] = [
        'score' => $score,
        'user_id' => (int)$row['user_id'],
        'fio' => $fio !== '' ? $fio : $name,
        'amount' => round($amount, 2),
        'lessons' => $lessons,
        'balance' => $bal,
        'balance_kind' => $bal < 0 ? 'долг' : 'баланс',
        'debt_azn' => $bal < 0 ? round(debt_azn($bal, $money), 2) : 0,
        'tone' => balance_tone($bal, $mode),
    ];
}
usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
$matches = array_slice($scored, 0, 5);
if (isset($matches[0]) && $matches[0]['score'] >= 80) {
    $second = $matches[1]['score'] ?? 0;
    if ($second < $matches[0]['score'] - 12) {
        $matches = [$matches[0]];
    }
}
foreach ($matches as &$m) unset($m['score']);
unset($m);

echo json_encode([
    'ok' => true,
    'text' => $text,
    'name' => $parsed['name'],
    'matches' => $matches,
], JSON_UNESCAPED_UNICODE);

function voice_parse(string $text): array {
    $t = mb_strtolower($text, 'UTF-8');
    $t = str_replace('ё', 'е', $t);
    $t = voice_ru_numbers($t);
    $t = preg_replace('/\b(\d{2})\s+(?:0|ноль)\b/u', '${1}0', $t) ?? $t;
    $t = preg_replace('/\b(\d)\s+(\d{2})\b/u', '$1$2', $t) ?? $t;
    $lessons = 0;
    $amount = 0.0;
    if (preg_match('/(\d+)\s*ур(?:оков|ока|ок)?\.?/u', $t, $m)) {
        $lessons = (int)$m[1];
        $t = str_replace($m[0], ' ', $t);
    }
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:манат(?:а|ов)?|азн|azn|ман)?/u', $t, $m)) {
        $amount = (float)str_replace(',', '.', $m[1]);
        if ($amount > 20000) $amount = 0;
        $t = str_replace($m[0], ' ', $t);
    }
    $stop = ['оплата', 'оплату', 'оплатил', 'оплатила', 'платеж', 'платёж', 'добавь', 'добавить', 'запиши', 'ученик', 'для'];
    foreach ($stop as $w) {
        $t = preg_replace('/\b' . preg_quote($w, '/') . '\b/u', ' ', $t) ?? $t;
    }
    $name = trim(preg_replace('/[^\p{L}\s-]+/u', ' ', $t) ?? '');
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    return ['name' => $name, 'amount' => $amount, 'lessons' => $lessons];
}

function voice_ru_numbers(string $t): string {
    $w = [
        'один'=>1,'одна'=>1,'два'=>2,'две'=>2,'три'=>3,'четыре'=>4,
        'пять'=>5,'шесть'=>6,'семь'=>7,'восемь'=>8,'девять'=>9,'десять'=>10,
        'одиннадцать'=>11,'двенадцать'=>12,'тринадцать'=>13,'четырнадцать'=>14,
        'пятнадцать'=>15,'шестнадцать'=>16,'семнадцать'=>17,'восемнадцать'=>18,'девятнадцать'=>19,
        'двадцать'=>20,'тридцать'=>30,'сорок'=>40,'пятьдесят'=>50,
        'шестьдесят'=>60,'семьдесят'=>70,'восемьдесят'=>80,'девяносто'=>90,
        'сто'=>100,'двести'=>200,
    ];
    $word = implode('|', array_keys($w));
    return preg_replace_callback('/\b(?:'.$word.')(?:\s+(?:'.$word.')){0,2}\b/u', static function ($m) use ($w) {
        $n = 0;
        foreach (preg_split('/\s+/u', $m[0]) as $p) {
            $n += $w[$p] ?? 0;
        }
        return $n > 0 ? (string)$n : $m[0];
    }, $t) ?? $t;
}

function voice_key(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $from = ['ё','е','э','й','а','б','в','г','д','ж','з','и','к','л','м','н','о','п','р','с','т','у','ф','х','ц','ч','ш','щ','ы','ю','я','ь','ъ','ә','ı','ö','ü','ğ','ş','ç'];
    $to   = ['e','e','e','i','a','b','v','g','d','z','z','i','k','l','m','n','o','p','r','s','t','u','f','h','c','c','s','s','y','u','a','','','a','i','o','u','g','s','c'];
    $s = str_replace($from, $to, $s);
    return preg_replace('/[^a-z0-9]+/u', '', $s) ?? '';
}

function voice_score(string $q, string $target): int {
    if ($q === '' || $target === '') return 0;
    if ($q === $target) return 100;
    if (str_starts_with($target, $q) || str_starts_with($q, $target)) return 88;
    if (strlen($q) >= 3 && (str_contains($target, $q) || str_contains($q, $target))) return 72;
    $d = levenshtein($q, $target);
    $max = max(strlen($q), strlen($target));
    if ($d <= 1 && $max >= 4) return 80;
    if ($d <= 2 && $max >= 6) return 68;
    return 0;
}
