<?php
declare(strict_types=1);

function parent_report_cycle(mysqli $con, int $user_id, int $bal, int $paid_lessons, ?array $last_pay): array {
    $offset = $bal < 0
        ? $paid_lessons
        : max(0, $paid_lessons - (int)($last_pay['lessons'] ?? 0));
    $from = '';
    $visits = 0;
    $st = $con->prepare("SELECT `dates` FROM dates WHERE user_id=? AND COALESCE(visited,0)=1 ORDER BY `dates`, dates_id LIMIT 1 OFFSET ?");
    $st->bind_param('ii', $user_id, $offset);
    $st->execute();
    $from = (string)($st->get_result()->fetch_assoc()['dates'] ?? '');
    $st->close();
    if ($from !== '') {
        $st = $con->prepare("SELECT SUM(COALESCE(visited,0)=1) FROM dates WHERE user_id=? AND `dates`>=?");
        $st->bind_param('is', $user_id, $from);
        $st->execute();
        $st->bind_result($visits);
        $st->fetch();
        $st->close();
    }
    return ['from' => $from, 'visits' => (int)$visits];
}

function lesson_word(int $n): string {
    $n = abs($n);
    $n10 = $n % 10;
    $n100 = $n % 100;
    if ($n10 === 1 && $n100 !== 11) return 'урок';
    if ($n10 >= 2 && $n10 <= 4 && ($n100 < 12 || $n100 > 14)) return 'урока';
    return 'уроков';
}

function prepaid_late_text(string $pm, int $bal): string {
    if ($pm !== 'prepaid' || $bal >= 0) return '';
    $n = abs($bal);
    return 'Должен был оплатить '.$n.' '.lesson_word($n).' назад';
}

function prepaid_note_kind(string $pm, int $bal): string {
    if ($pm !== 'prepaid' || $bal >= 0) return '';
    return $bal <= -current_debt_threshold() ? 'err' : 'warn';
}

function prepaid_remain_text(string $pm, int $bal): string {
    if ($bal <= 0) return '';
    return 'До оплаты '.$bal.' '.lesson_word($bal);
}

function postpaid_remain_text(string $pm, int $until, int $bal = 0): string {
    if ($pm !== 'postpaid' || $bal > 0) return '';
    if ($until <= 0 || student_is_debtor($bal, $pm)) return 'Просроченная оплата';
    return 'До оплаты '.$until.' '.lesson_word($until).' (платит в конце месяца)';
}

function postpaid_note_kind(string $pm, int $until, int $bal = 0): string {
    if ($pm !== 'postpaid' || $bal > 0) return '';
    if ($until <= 0 || student_is_debtor($bal, $pm)) return 'err';
    return 'warn';
}

function fmt_amount($a): string {
    return number_format((float)$a, 2, '.', '');
}

function fmt_date($d): string {
    if (empty($d)) return '—';
    $ts = strtotime((string)$d);
    return $ts ? date('d.m.Y', $ts) : h($d);
}

function trash_svg(): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>';
}

function render_visit_row(array $row, int $flash_id = 0): string {
    $date = fmt_date($row['dates']);
    $tm = hm($row['time'] ?? '');
    $ok = !empty($row['visited']);
    $status = $ok ? 'Пришёл' : 'Не пришёл';
    $label = $date . ($tm !== '' && $tm !== '00:00' ? ' · ' . $tm : '');
    $flash = $flash_id > 0 && (int)$row['dates_id'] === $flash_id ? ' is-flash' : '';
    return '<div class="hist-row'.$flash.'"><div class="name">'.h($label).'</div>'
        .'<span class="chip '.($ok ? 'ok' : 'bad').'">'.$status.'</span>'
        .'<button class="icon-btn js-del-visit" data-id="'.(int)$row['dates_id'].'" data-date="'.h($label).'" aria-label="Удалить">'.trash_svg().'</button></div>';
}

function render_pay_row(array $p, int $flash_id = 0): string {
    $date = fmt_date($p['date']);
    $lessons = (int)$p['lessons'];
    $mic = !empty($p['voice'])
        ? '<span class="pay-voice" title="Голосом" aria-label="Голосом"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg></span>'
        : '';
    $flash = $flash_id > 0 && (int)$p['id'] === $flash_id ? ' is-flash' : '';
    return '<div class="hist-row'.$flash.'"><div><div class="name">'.h($date).$mic.'</div><div class="sub">'.$lessons.' ур. · '.fmt_amount($p['amount']).' AZN</div></div>'
        .'<button class="icon-btn js-del-pay" data-id="'.(int)$p['id'].'" data-date="'.h($date).'" aria-label="Удалить">'.trash_svg().'</button></div>';
}

function student_card_stats(mysqli $con, int $user_id): array {
    $st = $con->prepare("SELECT COALESCE(money,0) AS money, pay_mode FROM stud WHERE user_id=?");
    $st->bind_param('i', $user_id);
    $st->execute();
    $stud = $st->get_result()->fetch_assoc();
    $st->close();
    $money = (float)($stud['money'] ?? 0);
    $pm = student_pay_mode($stud['pay_mode'] ?? 'prepaid');

    $st = $con->prepare("SELECT COUNT(*) FROM dates WHERE user_id=? AND visited=1");
    $st->bind_param('i', $user_id);
    $st->execute();
    $st->bind_result($visits_count);
    $st->fetch();
    $st->close();

    $st = $con->prepare("SELECT COALESCE(SUM(lessons),0), COUNT(*) FROM pays WHERE user_id=?");
    $st->bind_param('i', $user_id);
    $st->execute();
    $st->bind_result($paid_lessons, $pays_count);
    $st->fetch();
    $st->close();

    $st = $con->prepare("SELECT `date`, lessons, amount FROM pays WHERE user_id=? ORDER BY `date` DESC, id DESC LIMIT 1");
    $st->bind_param('i', $user_id);
    $st->execute();
    $last_pay = $st->get_result()->fetch_assoc();
    $st->close();

    $bal = (int)$paid_lessons - (int)$visits_count;
    $unit_price = pack_unit_price($money, $last_pay['amount'] ?? null, $last_pay['lessons'] ?? null);

    $cycle = parent_report_cycle($con, $user_id, $bal, (int)$paid_lessons, $last_pay);
    $period_from = $cycle['from'];
    $period_visits = $cycle['visits'];
    $period_missed = 0;

    $kind = student_alert_kind($bal, $pm);
    $until = current_debt_threshold() - (int)$period_visits;

    $html = '<div class="tone-'.h(balance_tone($bal, $pm)).'">'
        .'<b>'.($bal > 0 ? '+'.$bal : (string)$bal).'</b>'
        .'<span>'.($bal < 0 ? 'долг' : 'баланс').'</span>'
        .($bal < 0 ? '<span class="debt-azn">'.fmt_amount(debt_azn($bal, $unit_price)).' AZN</span>' : '')
        .($bal > 0
            ? '<span class="debt-azn">'.fmt_amount($bal * $unit_price).' AZN</span>'
            : ($pm === 'prepaid' && $bal === 0
                ? '<span class="debt-azn">пора оплатить</span>'
                : ($pm === 'postpaid'
                    ? '<span class="debt-azn">'.($until > 0 ? $until.' ур. до оплаты' : 'пора оплатить').'</span>'
                    : '')))
        .'</div>'
        .'<div><b>'.(int)$visits_count.'</b><span>визиты</span></div>'
        .'<div><b>'.(int)$paid_lessons.'</b><span>оплачено</span></div>'
        .'<div><b>'.(int)$pays_count.'</b><span>оплаты</span></div>';

    return [
        'html' => $html,
        'balance' => $bal,
        'tone' => balance_tone($bal, $pm),
        'kind' => $kind,
        'debt_azn' => $bal < 0 ? debt_azn($bal, $unit_price) : 0,
        'late' => ($late_txt = prepaid_late_text($pm, $bal)) !== '' ? $late_txt : (($remain_txt = prepaid_remain_text($pm, $bal)) !== '' ? $remain_txt : postpaid_remain_text($pm, $until, $bal)),
        'late_kind' => $late_txt !== '' ? prepaid_note_kind($pm, $bal) : ($remain_txt !== '' ? 'ok' : postpaid_note_kind($pm, $until, $bal)),
        'parent' => [
            'payMode' => $pm,
            'debtLessons' => $bal < 0 ? abs($bal) : 0,
            'debtAzn' => $bal < 0 ? debt_azn($bal, $unit_price) : 0,
            'remain' => max(0, $bal),
            'periodFrom' => $period_from !== '' ? fmt_date($period_from) : '',
            'visits' => (int)$period_visits,
            'missed' => (int)$period_missed,
            'lastDate' => $last_pay ? fmt_date($last_pay['date']) : '',
            'lastLessons' => $last_pay ? (int)$last_pay['lessons'] : 0,
            'lastAzn' => $last_pay ? (float)$last_pay['amount'] : 0,
        ],
    ];
}
