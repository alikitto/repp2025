<?php declare(strict_types=1); session_start(); ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8"/>
    <title>Вход — Tutor CRM</title>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <link rel="stylesheet" href="/profile/css/style.css">
</head>
<body>
    <div class="login-card">
        <h1>Вход</h1>
        <form method="post" action="/login.php">
            <label>Логин</label>
            <input name="login" required autofocus>
            
            <label>Пароль</label>
            <input type="password" name="password" required>
            
            <div style="margin-top: 10px; margin-bottom: 10px;">
                <label style="display: flex; align-items: center; cursor: pointer;">
                    <input type="checkbox" name="remember" value="1" style="width: auto; margin-right: 8px;">
                    Запомнить меня
                </label>
            </div>
            <button type="submit">Войти</button>
            
            <?php if (!empty($_GET['err'])): ?>
                <div class="error">Неверный логин или пароль</div>
            <?php endif; ?>
        </form>
    </div>
</body>
</html>
