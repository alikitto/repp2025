<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
require_once __DIR__ . '/../common/util.php';

$is_unlocked = false;
$error_message = '';
$fkey_ttl = 15 * 60;
$from = month_start($_GET['ym'] ?? null);
$ym = substr($from, 0, 7);
$to = date('Y-m-d', strtotime($from . ' +1 month'));
$prev_from = date('Y-m-d', strtotime($from . ' -1 month'));
$prev_ym = substr($prev_from, 0, 7);
$next_ym = date('Y-m', strtotime($from . ' +1 month'));
$cur_ym = date('Y-m');

$uid = (int)($_SESSION['id'] ?? 0);
$fkey_rev_db = 0;
if ($uid > 0) {
    $rv = $con->prepare("SELECT fkey_rev FROM users WHERE id=? LIMIT 1");
    $rv->bind_param('i', $uid);
    $rv->execute();
    $fkey_rev_db = (int)($rv->get_result()->fetch_assoc()['fkey_rev'] ?? 0);
    $rv->close();
}

if (!empty($_SESSION['fkey_unlocked']) && !empty($_SESSION['fkey_at']) && (time() - (int)$_SESSION['fkey_at']) > $fkey_ttl) {
    unset($_SESSION['fkey_unlocked'], $_SESSION['fkey_at'], $_SESSION['fkey_rev']);
}
if (!empty($_SESSION['fkey_unlocked']) && (int)($_SESSION['fkey_rev'] ?? -1) !== $fkey_rev_db) {
    unset($_SESSION['fkey_unlocked'], $_SESSION['fkey_at'], $_SESSION['fkey_rev']);
}

if (!empty($_SESSION['fkey_unlocked']) && $_SESSION['fkey_unlocked'] === true) {
    $is_unlocked = true;
    $_SESSION['fkey_at'] = time();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fkey'])) {
    csrf_check();
    $submitted_key = (string)$_POST['fkey'];
    $ip = client_ip();
    $rate_key = 'fkey:' . $uid;
    if (strlen($submitted_key) < 6) {
        $error_message = 'Задайте новый PIN (6–8 цифр) в профиле.';
    } elseif (login_rate_limited($ip, $rate_key)) {
        $error_message = 'Неверный ключ доступа';
    } else {
        $st = $con->prepare("SELECT fkey FROM users WHERE id = ? LIMIT 1");
        $st->bind_param('i', $uid);
        $st->execute();
        $result = $st->get_result()->fetch_assoc();
        $st->close();
        $stored = (string)($result['fkey'] ?? '');
        $ok = $stored !== '' && password_verify($submitted_key, $stored);
        if ($ok) {
            login_rate_clear($ip, $rate_key);
            $_SESSION['fkey_unlocked'] = true;
            $_SESSION['fkey_at'] = time();
            $_SESSION['fkey_rev'] = $fkey_rev_db;
            header('Location: /profile/finance.php?ym=' . urlencode($ym));
            exit;
        }
        login_rate_hit($ip, $rate_key);
        $error_message = 'Неверный ключ доступа';
    }
}

$months = [1=>'январь',2=>'февраль',3=>'март',4=>'апрель',5=>'май',6=>'июнь',7=>'июль',8=>'август',9=>'сентябрь',10=>'октябрь',11=>'ноябрь',12=>'декабрь'];
$months_short = [1=>'янв',2=>'фев',3=>'мар',4=>'апр',5=>'май',6=>'июн',7=>'июл',8=>'авг',9=>'сен',10=>'окт',11=>'ноя',12=>'дек'];

$cash = 0.0;
$cash_prev = 0.0;
$pending_azn = 0.0;
$pending_lessons = 0;
$income_total = 0.0;
$total_debt = 0.0;
$total_lessons = 0;
$delta_pct = null;
$debtors = [];
$soon = [];
$pays = [];
$chart = [];

if ($is_unlocked) {
    $tid = teacher_id();
    $st = $con->prepare("SELECT COALESCE(SUM(p.amount),0) AS total FROM pays p JOIN stud s ON s.user_id=p.user_id WHERE s.teacher_id=? AND p.date >= ? AND p.date < ?");
    $st->bind_param('iss', $tid, $from, $to);
    $st->execute();
    $cash = (float)$st->get_result()->fetch_assoc()['total'];
    $st->close();

    $st = $con->prepare("SELECT COALESCE(SUM(p.amount),0) AS total FROM pays p JOIN stud s ON s.user_id=p.user_id WHERE s.teacher_id=? AND p.date >= ? AND p.date < ?");
    $st->bind_param('iss', $tid, $prev_from, $from);
    $st->execute();
    $cash_prev = (float)$st->get_result()->fetch_assoc()['total'];
    $st->close();

    if ($cash_prev > 0) {
        $delta_pct = (($cash - $cash_prev) / $cash_prev) * 100;
    } elseif ($cash > 0) {
        $delta_pct = 100.0;
    }

    $inc = $con->prepare("SELECT COALESCE(SUM(p.amount),0) AS total FROM pays p JOIN stud s ON s.user_id=p.user_id WHERE s.teacher_id=?");
    $inc->bind_param('i', $tid);
    $inc->execute();
    $income_total = (float)$inc->get_result()->fetch_assoc()['total'];
    $inc->close();

    $st = $con->prepare("
        SELECT s.user_id,
               TRIM(CONCAT(s.lastname,' ',s.name)) AS fio,
               COALESCE(s.money,0) AS price,
               s.pay_mode,
               COALESCE(p.paid_lessons,0) AS paid_lessons,
               COALESCE(v.visits,0) AS visits,
               p.last_pay,
               lp.amount AS last_amount,
               lp.lessons AS last_lessons
        FROM stud s
        LEFT JOIN (
            SELECT user_id, SUM(lessons) AS paid_lessons, MAX(date) AS last_pay
            FROM pays GROUP BY user_id
        ) p ON p.user_id = s.user_id
        LEFT JOIN pays lp ON lp.id = (SELECT MAX(id) FROM pays px WHERE px.user_id=s.user_id)
        LEFT JOIN (
            SELECT user_id, COUNT(*) AS visits
            FROM dates WHERE visited = 1 GROUP BY user_id
        ) v ON v.user_id = s.user_id
        WHERE s.teacher_id=? AND s.archived=0
        ORDER BY fio
    ");
    $st->bind_param('i', $tid);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    foreach ($rows as $r) {
        $bal = (int)$r['paid_lessons'] - (int)$r['visits'];
        $debt = debt_azn($bal, pack_unit_price((float)$r['price'], $r['last_amount'] ?? null, $r['last_lessons'] ?? null));
        $mode = student_pay_mode($r['pay_mode'] ?? 'prepaid');
        $item = [
            'user_id' => (int)$r['user_id'],
            'fio' => $r['fio'],
            'balance' => $bal,
            'debt' => $debt,
            'last_pay' => $r['last_pay'],
            'pay_mode' => $mode,
        ];
        if ($bal < 0) {
            $pending_lessons += abs($bal);
            $pending_azn += $debt;
        }
        if (student_is_debtor($bal, $mode)) {
            $debtors[] = $item;
            $total_debt += $debt;
            $total_lessons += abs($bal);
        }
        if (student_is_soon($bal, $mode)) $soon[] = $item;
    }
    usort($debtors, fn($a, $b) => $a['balance'] <=> $b['balance']);
    usort($soon, fn($a, $b) => $a['balance'] <=> $b['balance']);

    $st = $con->prepare("
        SELECT p.id, p.user_id, p.date, p.lessons, p.amount, p.voice,
               TRIM(CONCAT(s.lastname,' ',s.name)) AS fio
        FROM pays p
        JOIN stud s ON s.user_id = p.user_id
        WHERE s.teacher_id=? AND p.date >= ? AND p.date < ?
        ORDER BY p.date DESC, p.id DESC
    ");
    $st->bind_param('iss', $tid, $from, $to);
    $st->execute();
    $pays = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $period = chart_period($con);
    [$chart_from, $chart_to, $chart_n] = finance_chart_span($period, $from);
    $st = $con->prepare("
        SELECT DATE_FORMAT(p.date, '%Y-%m') AS ym, SUM(p.amount) AS total
        FROM pays p JOIN stud s ON s.user_id=p.user_id
        WHERE s.teacher_id=? AND p.date >= ? AND p.date < ?
        GROUP BY ym
    ");
    $st->bind_param('iss', $tid, $chart_from, $chart_to);
    $st->execute();
    $chart_raw = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $chart_raw[$r['ym']] = (float)$r['total'];
    }
    $st->close();
    $cursor = $chart_from;
    for ($i = 0; $i < $chart_n; $i++) {
        $key = substr($cursor, 0, 7);
        $chart[] = ['ym' => $key, 'total' => $chart_raw[$key] ?? 0.0];
        $cursor = date('Y-m-01', strtotime($cursor . ' +1 month'));
    }
}

$chart_max = 0.0;
foreach ($chart as $c) {
    if ($c['total'] > $chart_max) $chart_max = $c['total'];
}
$chart_title = 'Заработано';
$chart_pick = null;
if ($chart) {
    $a = $chart[0]['ym'];
    $b = $chart[count($chart) - 1]['ym'];
    $chart_title = $months[(int)substr($a, 5, 2)] . ' ' . substr($a, 0, 4)
        . ' — ' . $months[(int)substr($b, 5, 2)] . ' ' . substr($b, 0, 4);
    $chart_pick = $chart[count($chart) - 1];
    foreach ($chart as $c) {
        if ($c['ym'] === $ym) { $chart_pick = $c; break; }
    }
}

$active = 'finance';
$back = '/profile/schedule.php';
$month_title = $months[(int)substr($from, 5, 2)] . ' ' . substr($from, 0, 4);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>Финансы — Tutor CRM</title>
    <link href="<?= asset('/profile/css/style.css') ?>" rel="stylesheet">
</head>
<body>

<?php require __DIR__ . '/../common/nav.php'; ?>

<div class="content">
    <?php if ($is_unlocked): ?>
        <div class="fin-month">
            <a href="/profile/finance.php?ym=<?= h($prev_ym) ?>" aria-label="Предыдущий месяц">‹</a>
            <h1><?= h($month_title) ?></h1>
            <?php if ($next_ym <= $cur_ym): ?>
                <a href="/profile/finance.php?ym=<?= h($next_ym) ?>" aria-label="Следующий месяц">›</a>
            <?php else: ?>
                <span class="is-off" aria-hidden="true">›</span>
            <?php endif; ?>
        </div>

        <div class="fin-stat">
            <b><?= fmt_money($cash) ?></b>
            <span>Заработано</span>
            <?php if ($delta_pct !== null): ?>
                <small class="<?= $delta_pct >= 0 ? 'up' : 'down' ?>"><?= ($delta_pct >= 0 ? '+' : '') . number_format($delta_pct, 0, '.', '') ?>%</small>
            <?php else: ?>
                <small class="muted">к прошлому месяцу</small>
            <?php endif; ?>
        </div>

        <section class="fin-summary">
            <h2>Сводка</h2>
            <div class="fin-stats">
                <div class="fin-stat">
                    <span class="fin-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></span>
                    <div>
                        <b><?= fmt_money($pending_azn) ?></b>
                        <span>В ожидании</span>
                        <small><?= (int)$pending_lessons ?> ур.</small>
                    </div>
                </div>
                <div class="fin-stat">
                    <span class="fin-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span>
                    <div>
                        <b><?= fmt_money($total_debt) ?></b>
                        <span>Долг</span>
                        <small><?= (int)$total_lessons ?> ур.</small>
                    </div>
                </div>
                <div class="fin-stat">
                    <span class="fin-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4h-4z"/></svg></span>
                    <div>
                        <b><?= fmt_money($income_total) ?></b>
                        <span>Заработано всего</span>
                    </div>
                </div>
            </div>
        </section>

        <div class="fin-head">
            <h2><?= h($chart_title) ?></h2>
        </div>
        <div class="card fin-chart-card">
            <div class="fin-chart-wrap">
                <div class="fin-chart" id="finChart">
                    <?php foreach ($chart as $c):
                        $hgt = $chart_max > 0 ? max(2, (int)round($c['total'] / $chart_max * 96)) : 2;
                        $m = (int)substr($c['ym'], 5, 2);
                        $label = $months[$m] . ' ' . substr($c['ym'], 0, 4);
                    ?>
                        <button type="button" class="fin-bar<?= $chart_pick && $c['ym'] === $chart_pick['ym'] ? ' is-on' : '' ?>" data-total="<?= h(fmt_money($c['total'])) ?>" data-label="<?= h($label) ?>">
                            <i style="height:<?= $hgt ?>px"></i>
                            <em><?= h($months_short[$m]) ?></em>
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php if ($chart_pick):
                    $pm = (int)substr($chart_pick['ym'], 5, 2);
                ?>
                    <div class="fin-chart-side" id="finChartSide">
                        <b><?= fmt_money($chart_pick['total']) ?></b>
                        <span><?= h($months[$pm] . ' ' . substr($chart_pick['ym'], 0, 4)) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="fin-head">
            <h2>Должники</h2>
            <span><?= fmt_money($total_debt) ?> AZN</span>
        </div>
        <?php if (!$debtors): ?>
            <p class="muted">Должников нет</p>
        <?php else: ?>
            <div class="fin-list">
                <?php foreach ($debtors as $r): ?>
                    <a class="fin-row" href="/profile/student.php?user_id=<?= (int)$r['user_id'] ?>">
                        <div>
                            <div class="name"><?= h($r['fio']) ?> <span class="chip <?= student_pay_mode_chip($r['pay_mode'] ?? 'prepaid') ?>"><?= h(student_pay_mode_label($r['pay_mode'] ?? 'prepaid')) ?></span></div>
                            <div class="sub"><?= $r['last_pay'] ? 'оплата '.h($r['last_pay']) : 'оплат нет' ?></div>
                        </div>
                        <div class="meta">
                            <?php $b = (int)$r['balance']; ?>
                            <span class="bal <?= balance_tone($b, $r['pay_mode'] ?? 'prepaid') ?>"><?= $b < 0 ? '−'.abs($b) : $b ?></span>
                            <?php if ($r['debt'] > 0): ?><div class="azn debt"><?= fmt_money($r['debt']) ?> AZN</div><?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="fin-head">
            <h2>Оплаты месяца</h2>
            <span><?= count($pays) ?> · <?= fmt_money($cash) ?> AZN</span>
        </div>
        <?php if (!$pays): ?>
            <p class="muted">Оплат в этом месяце нет</p>
        <?php else: ?>
            <div class="fin-list">
                <?php foreach ($pays as $p): ?>
                    <a class="fin-row" href="/profile/student.php?user_id=<?= (int)$p['user_id'] ?>">
                        <div>
                            <div class="name"><?= h($p['fio']) ?><?php if (!empty($p['voice'])): ?><span class="pay-voice" title="Голосом" aria-label="Голосом"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg></span><?php endif; ?></div>
                            <div class="sub"><?= h($p['date']) ?> · <?= (int)$p['lessons'] ?> ур.</div>
                        </div>
                        <div class="meta">
                            <div class="azn"><?= fmt_money($p['amount']) ?> AZN</div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="fin-head">
            <h2>Скоро оплата</h2>
        </div>
        <?php if (!$soon): ?>
            <p class="muted">Ближайших оплат нет</p>
        <?php else: ?>
            <div class="fin-list">
                <?php foreach ($soon as $r): ?>
                    <a class="fin-row" href="/profile/student.php?user_id=<?= (int)$r['user_id'] ?>">
                        <div>
                            <div class="name"><?= h($r['fio']) ?></div>
                            <div class="sub"><?php
                                if ($r['last_pay']) echo 'оплата ' . h($r['last_pay']);
                                elseif (($r['pay_mode'] ?? '') === 'postpaid') echo 'в конце месяца';
                                else echo 'пора оплатить пакет';
                            ?></div>
                        </div>
                        <div class="meta">
                            <?php $b = (int)$r['balance']; ?>
                            <span class="bal <?= balance_tone($b, $r['pay_mode'] ?? 'prepaid') ?>"><?= $b < 0 ? '−'.abs($b) : $b ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="card lock-container">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 16px; color: var(--muted);"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
            <h2>Доступ к финансам</h2>
            <p class="muted">PIN: 6–8 цифр. Сменить — в <a href="/profile/profile.php?tab=security">профиле</a>.</p>
            <form method="POST" action="/profile/finance.php?ym=<?= h($ym) ?>" style="margin-top: 16px;">
                <?= csrf_field() ?>
                <input type="password" name="fkey" class="input" inputmode="numeric" pattern="\d{6,8}" maxlength="8" autocomplete="one-time-code" placeholder="PIN" required autofocus>
                <?php if ($error_message): ?>
                    <p class="error"><?= h($error_message) ?></p>
                <?php endif; ?>
                <button type="submit" class="btn" style="margin-top: 12px; width: 100%;">Войти</button>
            </form>
        </div>
    <?php endif; ?>
</div>
<?php if ($is_unlocked && $chart): ?>
<script <?= csp_nonce_attr() ?>>
(function(){
  const chart = document.getElementById('finChart');
  const side = document.getElementById('finChartSide');
  if (!chart || !side) return;
  chart.addEventListener('click', e => {
    const bar = e.target.closest('.fin-bar');
    if (!bar) return;
    chart.querySelectorAll('.fin-bar.is-on').forEach(el => el.classList.remove('is-on'));
    bar.classList.add('is-on');
    side.querySelector('b').textContent = bar.dataset.total;
    side.querySelector('span').textContent = bar.dataset.label;
  });
})();
</script>
<?php endif; ?>

</body>
</html>
