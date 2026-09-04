<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/util.php';

$page = (int)($_GET['p'] ?? 1);
[$items, $total, $page, $pages] = activity_page($con, $page);
activity_mark_seen(activity_max_id($con));

$active = 'activity';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>Действия — Tutor CRM</title>
  <link href="<?= asset('/profile/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/../common/nav.php'; ?>

<div class="content">
  <div class="card">
    <div class="toolbar">
      <h1>Все действия</h1>
    </div>
    <?php if (!$items): ?>
      <p class="muted">Пока нет действий.</p>
    <?php else: ?>
      <div class="activity-list">
        <?php foreach ($items as $a): ?>
          <?php if (!empty($a['url'])): ?>
          <a class="activity-row" href="<?= h($a['url']) ?>">
          <?php else: ?>
          <div class="activity-row">
          <?php endif; ?>
            <div class="name"><?= h($a['text']) ?></div>
            <div class="when"><?= h(activity_time($a['created_at'])) ?></div>
          <?= !empty($a['url']) ? '</a>' : '</div>' ?>
        <?php endforeach; ?>
      </div>
      <?php if ($pages > 1): ?>
      <div class="pager">
        <?php if ($page > 1): ?>
          <a class="btn sm gray" href="/profile/activity.php?p=<?= $page - 1 ?>">Назад</a>
        <?php endif; ?>
        <p class="muted"><?= $page ?> / <?= $pages ?></p>
        <?php if ($page < $pages): ?>
          <a class="btn sm gray" href="/profile/activity.php?p=<?= $page + 1 ?>">Далее</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
