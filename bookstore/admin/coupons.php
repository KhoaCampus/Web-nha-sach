<?php
require_once __DIR__ . '/_auth.php';
$admin = require_admin();
$pdo   = db();

$blank = [
    'id' => 0, 'code' => '', 'discount_type' => 'percent', 'discount_value' => '', 'min_order' => '0',
    'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('+30 days')),
    'usage_limit' => '', 'per_user_limit' => '1', 'limit_period' => 'lifetime', 'is_active' => 1,
];
$form   = $blank;
$errors = [];

/* ---------- POST: lưu / bật tắt / xóa ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash_set('error', 'Phiên làm việc không hợp lệ, vui lòng thử lại.');
        redirect('coupons.php');
    }
    $action = $_POST['action'] ?? '';
    $cid    = (int)($_POST['id'] ?? 0);

    $cur = null;
    if ($cid > 0) {
        $st = $pdo->prepare('SELECT * FROM coupons WHERE id = ?');
        $st->execute([$cid]);
        $cur = $st->fetch();
        if (!$cur) {
            flash_set('error', 'Không tìm thấy mã khuyến mãi.');
            redirect('coupons.php');
        }
    }

    if ($action === 'toggle') {
        $pdo->prepare('UPDATE coupons SET is_active = 1 - is_active WHERE id = ?')->execute([$cid]);
        audit_log((int)$admin['id'], 'admin_coupon_toggle', 'coupons', $cid, $cur['code']);
        flash_set('success', $cur['is_active'] ? 'Đã tắt mã ' . $cur['code'] . '.' : 'Đã bật mã ' . $cur['code'] . '.');
        redirect('coupons.php');
    }

    if ($action === 'delete') {
        $st = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE coupon_id = ?');
        $st->execute([$cid]);
        if ((int)$st->fetchColumn() > 0) {
            flash_set('error', 'Mã ' . $cur['code'] . ' đã được dùng trong đơn hàng nên không thể xóa. Hãy tắt mã thay vì xóa.');
        } else {
            $pdo->prepare('DELETE FROM coupons WHERE id = ?')->execute([$cid]);
            audit_log((int)$admin['id'], 'admin_coupon_delete', 'coupons', $cid, $cur['code']);
            flash_set('success', 'Đã xóa mã ' . $cur['code'] . '.');
        }
        redirect('coupons.php');
    }

    if ($action === 'save') {
        foreach (['code', 'discount_type', 'discount_value', 'min_order', 'start_date', 'end_date', 'usage_limit', 'per_user_limit', 'limit_period'] as $k) {
            $form[$k] = trim((string)($_POST[$k] ?? ''));
        }
        $form['id']        = $cid;
        $form['code']      = strtoupper($form['code']);
        $form['is_active'] = isset($_POST['is_active']) ? 1 : 0;
        foreach (['discount_value', 'min_order'] as $k) { $form[$k] = preg_replace('/[.,\s]/', '', $form[$k]); }

        $isDate = static fn(string $d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;

        if (!preg_match('/^[A-Z0-9_-]{3,50}$/', $form['code'])) { $errors[] = 'Mã gồm 3–50 ký tự: chữ không dấu, số, dấu - hoặc _.'; }
        if (!in_array($form['discount_type'], ['percent', 'fixed'], true)) { $errors[] = 'Loại giảm giá không hợp lệ.'; }
        if (!preg_match('/^\d{1,10}$/', $form['discount_value']) || (int)$form['discount_value'] < 1) {
            $errors[] = 'Giá trị giảm phải là số nguyên từ 1 trở lên.';
        } elseif ($form['discount_type'] === 'percent' && (int)$form['discount_value'] > 100) {
            $errors[] = 'Giảm theo phần trăm tối đa 100%.';
        }
        if (!preg_match('/^\d{1,10}$/', $form['min_order'])) { $errors[] = 'Đơn tối thiểu phải là số nguyên không âm.'; }
        if (!$isDate($form['start_date']) || !$isDate($form['end_date'])) {
            $errors[] = 'Vui lòng chọn ngày bắt đầu và kết thúc hợp lệ.';
        } elseif ($form['end_date'] < $form['start_date']) {
            $errors[] = 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.';
        }
        if ($form['usage_limit'] !== '' && (!ctype_digit($form['usage_limit']) || (int)$form['usage_limit'] < 1)) {
            $errors[] = 'Tổng lượt dùng phải là số nguyên từ 1 trở lên (hoặc để trống nếu không giới hạn).';
        } elseif ($form['usage_limit'] !== '' && $cur && (int)$form['usage_limit'] < (int)$cur['used_count']) {
            $errors[] = 'Tổng lượt dùng không được nhỏ hơn số lượt đã dùng (' . (int)$cur['used_count'] . ').';
        }
        if ($form['per_user_limit'] !== '' && (!ctype_digit($form['per_user_limit']) || (int)$form['per_user_limit'] < 1)) {
            $errors[] = 'Lượt dùng mỗi người phải từ 1 trở lên (hoặc để trống nếu không giới hạn).';
        }
        if (!in_array($form['limit_period'], ['lifetime', 'month'], true)) { $errors[] = 'Chu kỳ giới hạn không hợp lệ.'; }

        if (!$errors) {
            $vals = [
                $form['code'], $form['discount_type'], (int)$form['discount_value'], (int)$form['min_order'],
                $form['start_date'], $form['end_date'],
                $form['usage_limit'] === '' ? null : (int)$form['usage_limit'],
                $form['per_user_limit'] === '' ? null : (int)$form['per_user_limit'],
                $form['limit_period'], $form['is_active'],
            ];
            try {
                if ($cid > 0) {
                    $pdo->prepare(
                        'UPDATE coupons SET code=?, discount_type=?, discount_value=?, min_order=?, start_date=?, end_date=?,
                                usage_limit=?, per_user_limit=?, limit_period=?, is_active=? WHERE id=?'
                    )->execute(array_merge($vals, [$cid]));
                } else {
                    $pdo->prepare(
                        'INSERT INTO coupons (code, discount_type, discount_value, min_order, start_date, end_date,
                                usage_limit, per_user_limit, limit_period, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute($vals);
                    $cid = (int)$pdo->lastInsertId();
                }
                audit_log((int)$admin['id'], $cur ? 'admin_coupon_update' : 'admin_coupon_create', 'coupons', $cid, $form['code']);
                flash_set('success', $cur ? 'Đã cập nhật mã ' . $form['code'] . '.' : 'Đã tạo mã ' . $form['code'] . '.');
                redirect('coupons.php');
            } catch (PDOException $ex) {
                $errors[] = ($ex->getCode() === '23000') ? 'Mã "' . $form['code'] . '" đã tồn tại.' : 'Có lỗi hệ thống, vui lòng thử lại.';
                if ($ex->getCode() !== '23000') { error_log('coupons: ' . $ex->getMessage()); }
            }
        }
    }
} elseif (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM coupons WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    if ($c = $st->fetch()) {
        $form = $c;
        foreach (['discount_value', 'min_order'] as $k) { $form[$k] = (string)(int)$form[$k]; }
        foreach (['usage_limit', 'per_user_limit'] as $k) { $form[$k] = $form[$k] === null ? '' : (string)$form[$k]; }
    }
}

$coupons = $pdo->query(
    'SELECT c.*, (SELECT COUNT(*) FROM orders o WHERE o.coupon_id = c.id) AS orders FROM coupons c ORDER BY c.is_active DESC, c.end_date DESC, c.id DESC'
)->fetchAll();

$showForm = $errors || isset($_GET['edit']) || isset($_GET['new']);
$today    = date('Y-m-d');

admin_head('Quản lý khuyến mãi', 'coupons');
?>
<div class="a-title">
    <div><h1>Quản lý khuyến mãi</h1><p><?= count($coupons) ?> mã giảm giá</p></div>
    <?php if (!$showForm): ?><a class="a-btn red" href="coupons.php?new=1">+ Tạo mã mới</a><?php endif; ?>
</div>

<?php if ($showForm): ?>
<div class="a-card">
    <h3><?= $form['id'] ? 'Sửa mã ' . e($form['code']) : 'Tạo mã khuyến mãi' ?></h3>
    <?php if ($errors): ?><div class="a-alert error"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form class="a-form" method="post" action="coupons.php" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
        <div class="row3">
            <div><label for="code">Mã *</label><input type="text" id="code" name="code" maxlength="50" placeholder="VD: GIAM10" style="text-transform:uppercase" value="<?= e($form['code']) ?>"></div>
            <div><label for="discount_type">Loại giảm</label>
                <select id="discount_type" name="discount_type">
                    <option value="percent" <?= $form['discount_type'] === 'percent' ? 'selected' : '' ?>>Phần trăm (%)</option>
                    <option value="fixed" <?= $form['discount_type'] === 'fixed' ? 'selected' : '' ?>>Số tiền cố định (đ)</option>
                </select></div>
            <div><label for="discount_value">Giá trị giảm *</label><input type="text" id="discount_value" name="discount_value" inputmode="numeric" value="<?= e($form['discount_value']) ?>"></div>
        </div>
        <div class="row3">
            <div><label for="min_order">Đơn tối thiểu (đ)</label><input type="text" id="min_order" name="min_order" inputmode="numeric" value="<?= e($form['min_order']) ?>"></div>
            <div><label for="start_date">Bắt đầu *</label><input type="date" id="start_date" name="start_date" value="<?= e($form['start_date']) ?>"></div>
            <div><label for="end_date">Kết thúc *</label><input type="date" id="end_date" name="end_date" value="<?= e($form['end_date']) ?>"></div>
        </div>
        <div class="row3">
            <div><label for="usage_limit">Tổng lượt dùng</label><input type="number" id="usage_limit" name="usage_limit" min="1" placeholder="Để trống = không giới hạn" value="<?= e($form['usage_limit']) ?>"></div>
            <div><label for="per_user_limit">Lượt dùng mỗi người</label><input type="number" id="per_user_limit" name="per_user_limit" min="1" placeholder="Để trống = không giới hạn" value="<?= e($form['per_user_limit']) ?>"></div>
            <div><label for="limit_period">Tính lượt theo</label>
                <select id="limit_period" name="limit_period">
                    <option value="lifetime" <?= $form['limit_period'] === 'lifetime' ? 'selected' : '' ?>>Trọn đời</option>
                    <option value="month" <?= $form['limit_period'] === 'month' ? 'selected' : '' ?>>Mỗi tháng</option>
                </select></div>
        </div>
        <label class="checks" style="margin-top:14px"><span style="display:flex;gap:6px;align-items:center;font-weight:400"><input type="checkbox" name="is_active" value="1" <?= $form['is_active'] ? 'checked' : '' ?>> Đang kích hoạt</span></label>
        <div class="actions"><button type="submit" class="a-btn red">Lưu mã</button><a class="a-btn line" href="coupons.php">Hủy</a></div>
    </form>
</div>
<?php endif; ?>

<div class="a-card">
    <div class="a-tablewrap">
        <table class="a-table">
            <thead><tr><th>Mã</th><th>Ưu đãi</th><th class="r">Đơn tối thiểu</th><th>Hiệu lực</th><th class="r">Lượt dùng</th><th>Mỗi người</th><th>Trạng thái</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($coupons as $c):
                if (!$c['is_active'])                                                  { $badge = ['off', 'Đã tắt']; }
                elseif ($today < $c['start_date'])                                     { $badge = ['warn', 'Sắp diễn ra']; }
                elseif ($today > $c['end_date'])                                       { $badge = ['bad', 'Hết hạn']; }
                elseif ($c['usage_limit'] !== null && $c['used_count'] >= $c['usage_limit']) { $badge = ['bad', 'Hết lượt']; }
                else                                                                   { $badge = ['ok', 'Đang chạy']; }
            ?>
                <tr>
                    <td class="code"><?= e($c['code']) ?></td>
                    <td><strong><?= $c['discount_type'] === 'percent' ? (int)$c['discount_value'] . '%' : e(money($c['discount_value'])) ?></strong></td>
                    <td class="r"><?= e(money($c['min_order'])) ?></td>
                    <td><?= e(date('d/m/Y', strtotime($c['start_date']))) ?> – <?= e(date('d/m/Y', strtotime($c['end_date']))) ?></td>
                    <td class="r"><?= (int)$c['used_count'] ?> / <?= $c['usage_limit'] === null ? '∞' : (int)$c['usage_limit'] ?></td>
                    <td><?= $c['per_user_limit'] === null ? 'Không giới hạn' : (int)$c['per_user_limit'] . ' lần' . ($c['limit_period'] === 'month' ? '/tháng' : '') ?></td>
                    <td><span class="a-badge <?= $badge[0] ?>"><?= $badge[1] ?></span></td>
                    <td class="act">
                        <a class="a-ico" href="coupons.php?edit=<?= (int)$c['id'] ?>" title="Sửa">✏️</a>
                        <form method="post" action="coupons.php"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button class="a-ico" type="submit" title="<?= $c['is_active'] ? 'Tắt mã' : 'Bật mã' ?>"><?= $c['is_active'] ? '⏸' : '▶️' ?></button></form>
                        <form method="post" action="coupons.php" onsubmit="return confirm('Xóa mã <?= e($c['code']) ?>?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button class="a-ico" type="submit" title="Xóa">🗑</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$coupons): ?><tr><td colspan="8" class="a-empty">Chưa có mã khuyến mãi nào.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php admin_foot(); ?>
