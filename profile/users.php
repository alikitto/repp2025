<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
require_once __DIR__ . '/../common/util.php';

require_admin();

$flash = '';
$flash_err = '';
$new_name = '';
$new_login = '';
$tab = (string)($_GET['tab'] ?? 'list');
if ($tab !== 'add') $tab = 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_teacher'])) {
    csrf_check();
    $tab = 'add';
    $new_name = trim((string)($_POST['name'] ?? ''));
    $new_login = trim((string)($_POST['login'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if ($new_name === '' || $new_login === '') {
        $flash_err = 'Имя и логин обязательны.';
    } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,64}$/', $new_login)) {
        $flash_err = 'Логин: 3–64 символа, латиница, цифры, . _ -';
    } elseif (is_reserved_login($con, $new_login)) {
        $flash_err = 'Этот логин зарезервирован.';
    } elseif (strlen($pass) < 8) {
        $flash_err = 'Пароль — минимум 8 символов.';
    } elseif (!hash_equals($pass, $pass2)) {
        $flash_err = 'Пароли не совпадают.';
    } else {
        $chk = $con->prepare("SELECT id FROM users WHERE login=? LIMIT 1");
        $chk->bind_param('s', $new_login);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $flash_err = 'Такой логин уже занят.';
        }
        $chk->close();
    }

    if ($flash_err === '') {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $ins = $con->prepare("INSERT INTO users (login, password_hash, name, role) VALUES (?, ?, ?, 'teacher')");
        $ins->bind_param('sss', $new_login, $hash, $new_name);
        if ($ins->execute()) {
            $_SESSION['flash_teacher'] = 'Учитель создан.';
            log_activity($con, 'settings', 'Создан учитель ' . $new_login, '/profile/users.php');
            header('Location: /profile/users.php?tab=list');
            exit;
        }
        $flash_err = 'Не удалось создать учётную запись.';
        $ins->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_teacher_pass'])) {
    csrf_check();
    $tab = 'list';
    $tid_reset = (int)($_POST['teacher_id'] ?? 0);
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    if ($tid_reset <= 0) {
        $flash_err = 'Выберите учителя.';
    } elseif (strlen($pass) < 8) {
        $flash_err = 'Пароль — минимум 8 символов.';
    } elseif (!hash_equals($pass, $pass2)) {
        $flash_err = 'Пароли не совпадают.';
    } else {
        $chk = $con->prepare("SELECT id, login FROM users WHERE id=? AND role='teacher' LIMIT 1");
        $chk->bind_param('i', $tid_reset);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$row) {
            $flash_err = 'Учитель не найден.';
        } else {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $up = $con->prepare("UPDATE users SET password_hash=? WHERE id=?");
            $up->bind_param('si', $hash, $tid_reset);
            $up->execute();
            $up->close();
            remember_revoke_user($con, $tid_reset);
            bump_user_session_rev($con, $tid_reset);
            $_SESSION['flash_teacher'] = 'Пароль обновлён.';
            log_activity($con, 'settings', 'Сброшен пароль учителя ' . $row['login'], '/profile/users.php');
            header('Location: /profile/users.php?tab=list');
            exit;
        }
    }
}

if ($flash === '') {
    $flash = (string)($_SESSION['flash_teacher'] ?? '');
    unset($_SESSION['flash_teacher']);
}

$teachers = $con->query("SELECT id, login, name FROM users WHERE role='teacher' ORDER BY name, login")->fetch_all(MYSQLI_ASSOC);
$counts = [];
$res = $con->query("SELECT teacher_id, COUNT(*) AS n FROM stud GROUP BY teacher_id");
if ($res) {
    while ($r = $res->fetch_assoc()) $counts[(int)$r['teacher_id']] = (int)$r['n'];
}

$active = 'users';
$back = $tab === 'add' ? '/profile/users.php' : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>Учителя — Tutor CRM</title>
    <link href="<?= asset('/profile/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/../common/nav.php'; ?>
<div class="content">
    <?php if ($flash): ?><p class="notice"><?= h($flash) ?></p><?php endif; ?>
    <?php if ($flash_err): ?><p class="notice err"><?= h($flash_err) ?></p><?php endif; ?>

    <?php if ($tab === 'add'): ?>
    <div class="card">
        <h2>Новый учитель</h2>
        <form method="post" action="/profile/users.php?tab=add" class="form" autocomplete="off">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="name">Имя</label>
                <input class="input" type="text" id="name" name="name" required value="<?= h($new_name) ?>">
            </div>
            <div class="form-group">
                <label for="login">Логин</label>
                <input class="input" type="text" id="login" name="login" required value="<?= h($new_login) ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="password">Пароль</label>
                <input class="input" type="password" id="password" name="password" required autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="password2">Повторите пароль</label>
                <input class="input" type="password" id="password2" name="password2" required autocomplete="new-password">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn" name="create_teacher" value="1">Создать</button>
            </div>
        </form>
    </div>
    <?php else: ?>
    <div class="card students-card">
    <div class="toolbar">
      <h1>Учителя</h1>
      <a class="btn sm" href="/profile/users.php?tab=add">Добавить</a>
    </div>
    <?php if (!$teachers): ?>
        <p class="muted students-empty">Учителей пока нет.</p>
    <?php else: ?>
      <table class="table students">
        <thead>
          <tr>
            <th>Имя</th>
            <th>Логин</th>
            <th class="num">Ученики</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($teachers as $t): $tid = (int)$t['id']; ?>
          <tr>
            <td><a href="/profile/teacher.php?id=<?= $tid ?>"><?= h((string)($t['name'] ?: $t['login'])) ?></a></td>
            <td><?= h((string)$t['login']) ?></td>
            <td class="num"><?= (int)($counts[$tid] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    </div>
    <?php if ($teachers): ?>
    <div class="card" style="margin-top:16px">
        <h2>Сбросить пароль</h2>
        <form method="post" class="form" autocomplete="off">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="teacher_id">Учитель</label>
                <select class="input select-big" id="teacher_id" name="teacher_id" required>
                    <?php foreach ($teachers as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"><?= h((string)($t['name'] ?: $t['login'])) ?> (<?= h((string)$t['login']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="reset_password">Новый пароль</label>
                <input class="input" type="password" id="reset_password" name="password" required autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="reset_password2">Повторите пароль</label>
                <input class="input" type="password" id="reset_password2" name="password2" required autocomplete="new-password">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn" name="reset_teacher_pass" value="1">Сохранить пароль</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
