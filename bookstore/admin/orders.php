<?php
require_once __DIR__ . '/_auth.php';
require_admin();
$pdo = db();

/* ---------- Bộ lọc ---------- */
$q      = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$from   = $_GET['from'] ?? '';
$to     = $_GET['to'] ?? '';
$isDate = static fn(string $d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;

if (!isset($GLOBALS['ORDER_STATUS'][$status])) { $status = ''; }
if (!$isDate($from)) { $from = ''; }
if (!$isDate($to))   { $to = ''; }

$where  = ['1=1'];
$params = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(o.order_code LIKE ? OR o.receiver_name LIKE ? OR o.phone LIKE ? OR u.email LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
if ($status !== '') { $where[] = 'o.status = ?'; $params[] = $status; }
if ($from !== '')   { $where[] = 'o.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '')     { $where[] = 'o.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
$whereSql = implode(' AND ', $where);

$perPage = 12;
$st = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN users u ON u.id = o.user_id WHERE $whereSql");
$st->execute($params);
$total      = (int)$st->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
$offset     = ($page - 1) * $perPage;

$st = $pdo->prepare(
    "SELECT o.id, o.order_code, o.receiver_name, o.phone, o.total_amount, o.status, o.created_at, u.email,
            (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = o.id) AS qty,
            (SELECT method FROM payments WHERE order_id = o.id ORDER BY id DESC LIMIT 1) AS pay_method
     FROM orders o JOIN users u ON u.id = o.user_id
     WHERE $whereSql ORDER BY o.created_at DESC, o.id DESC LIMIT $perPage OFFSET $offset"
);
$st->execute($params);
$orders = $st->fetchAll();

$counts = $pdo->query('SELECT status, COUNT(*) FROM orders GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);

admin_head('Quản lý đơn hàng', 'orders', ['action' => 'orders.php', 'placeholder' => 'Tìm mã đơn, tên, SĐT, email...']);
?>
<div class="a-title">
    <div><h1>Danh sách đơn hàng</h1><p><?= $total ?> đơn hàng<?= ($q !== '' || $status !== '' || $from !== '' || $to !== '') ? ' phù hợp bộ lọc' : '' ?></p></div>
</div>

<form class="a-filter" method="get" action="orders.php">
    <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
    <select name="status" onchange="this.form.submit()">
        <option value="">Tất cả trạng thái</option>
        <?php foreach ($GLOBALS['ORDER_STATUS'] as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?> (<?= (int)($counts[$k] ?? 0) ?>)</option>
        <?php endforeach; ?>
    </select>
    <span class="muted">Từ</span><input type="date" name="from" value="<?= e($from) ?>">
    <span class="muted">đến</span><input type="date" name="to" value="<?= e($to) ?>">
    <button type="submit" class="a-btn red sm">Lọc</button>
    <?php if ($q !== '' || $status !== '' || $from !== '' || $to !== ''): ?><a class="a-btn line sm" href="orders.php">Xóa lọc</a><?php endif; ?>
</form>

<div class="a-card">
    <div class="a-tablewrap">
        <table class="a-table">
            <thead><tr><th>Mã đơn</th><th>Khách hàng</th><th>Ngày đặt</th><th>SL</th><th>Thanh toán</th><th class="r">Tổng tiền</th><th>Trạng thái</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td class="code"><a href="order_view.php?id=<?= (int)$o['id'] ?>">#<?= e($o['order_code']) ?></a></td>
                    <td><?= e($o['receiver_name']) ?><br><small class="muted"><?= e($o['phone']) ?> · <?= e($o['email']) ?></small></td>
                    <td><?= e(date('d/m/Y H:i', strtotime($o['created_at']))) ?></td>
                    <td><?= (int)$o['qty'] ?></td>
                    <td><?= e($GLOBALS['PAYMENT_METHODS'][$o['pay_method']] ?? '—') ?></td>
                    <td class="r"><strong><?= e(money($o['total_amount'])) ?></strong></td>
                    <td><?= status_badge($o['status']) ?></td>
                    <td class="act"><a class="a-ico" href="order_view.php?id=<?= (int)$o['id'] ?>" title="Xem / xử lý">👁</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$orders): ?><tr><td colspan="8" class="a-empty">Không có đơn hàng nào.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= pager_html($page, $totalPages) ?>
</div>
<?php admin_foot(); ?>
