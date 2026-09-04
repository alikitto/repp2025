<?php
require_once __DIR__ . '/../profile/_auth.php';
require_once __DIR__ . '/../common/csrf.php';
$csrf_val = function_exists('csrf_token')
  ? csrf_token()
  : (isset($_SESSION['csrf']) ? $_SESSION['csrf'] : ($_SESSION['csrf']=bin2hex(random_bytes(32))));

$classes = range(5,11);

require_once __DIR__ . '/../db_conn.php';

// FLASH из сессии (успех создания)
$flash = $_SESSION['flash_created'] ?? null;
unset($_SESSION['flash_created']);
$flash_err = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);
$flash_warn = $_SESSION['flash_warn'] ?? '';
unset($_SESSION['flash_warn']);
$created   = is_array($flash);
$createdId = $created ? (int)$flash['id'] : 0;
$createdNm = $created ? htmlspecialchars($flash['name']) : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>Добавить ученика — Tutor CRM</title>
  <link rel="stylesheet" href="<?= asset('/profile/css/style.css') ?>">
</head>
<body>

<?php $active='add-student'; $back='/profile/list.php'; $back_warn=true; require __DIR__ . '/../common/nav.php'; ?>

<div class="content">
  <div class="card">
    <h2>Добавить ученика</h2>
    <?php if ($flash_err): ?><p class="notice err"><?= htmlspecialchars($flash_err) ?></p><?php endif; ?>
    <?php if ($flash_warn): ?><p class="notice warn"><?= htmlspecialchars($flash_warn) ?></p><?php endif; ?>

    <form action="/add/save.php" method="post" class="form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf_val) ?>">

      <div class="grid-2">
        <div class="form-group">
          <label for="name">Имя</label>
          <input class="input" type="text" id="name" name="name" required>
        </div>
        <div class="form-group">
          <label for="lastname">Фамилия</label>
          <input class="input" type="text" id="lastname" name="lastname">
        </div>
      </div>
      <div class="form-group">
        <label for="klass">Класс</label>
        <select id="klass" name="klass" class="input select-big">
          <option value="">— не выбрано —</option>
          <?php foreach ($classes as $k): ?>
            <option value="<?= $k ?>"><?= $k ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="money">Цена урока (AZN)</label>
        <input class="input" type="number" id="money" name="money" inputmode="decimal" step="0.01" min="0.01" value="<?= htmlspecialchars(fmt_price(default_lesson_price($con)), ENT_QUOTES, 'UTF-8') ?>">
        <?= price_preset_buttons($con) ?>
      </div>
      <div class="form-group">
        <label for="pay_mode">Как платит</label>
        <select id="pay_mode" name="pay_mode" class="input select-big">
          <option value="prepaid" selected>Предоплата</option>
          <option value="postpaid">В конце месяца</option>
        </select>
      </div>

      <button type="submit" class="btn" style="margin-top:12px;">
        <svg class="btn-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Создать ученика
      </button>
    </form>
  </div>
</div>

<script <?= csp_nonce_attr() ?>>
<?php if ($created): ?>
window.showActionOk(
  'Успешно добавлен ученик ' + <?= json_encode((string)($flash['name'] ?? 'Ученик'), JSON_UNESCAPED_UNICODE) ?>,
  '/profile/student.php?user_id=<?= (int)$createdId ?>'
);
<?php endif; ?>
(function(){
  const money = document.getElementById('money');
  const presets = document.querySelectorAll('.price-preset');
  function syncPrice(){
    const v = parseFloat(money?.value);
    presets.forEach(p => p.classList.toggle('is-active', parseFloat(p.dataset.price) === v));
  }
  presets.forEach(p => p.addEventListener('click', () => { money.value = p.dataset.price; syncPrice(); }));
  money?.addEventListener('input', syncPrice);
  syncPrice();
})();
</script>
</body>
</html>
