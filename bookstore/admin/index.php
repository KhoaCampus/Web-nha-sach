<?php
require_once __DIR__ . '/_auth.php';
require_admin();
$pdo = db();

/* ---------- Thẻ thống kê (so sánh 30 ngày gần nhất với 30 ngày trước đó) ---------- */
$scalar = static function (string $sql) use ($pdo) { return (float)$pdo->query($sql)->fetchColumn(); };

$totUsers   = (int)$scalar("SELECT COUNT(*) FROM users WHERE role = 'user' AND status <> 'deleted'");
$totOrders  = (int)$scalar('SELECT COUNT(*) FROM orders');
$totBooks   = (int)$scalar("SELECT COUNT(*) FROM books WHERE status <> 'deleted'");
$totRevenue = $scalar("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'completed'");

$new  = static fn(string $tbl, string $extra = '') => "SELECT COUNT(*) FROM $tbl WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) $extra";
$prev = static fn(string $tbl, string $extra = '') => "SELECT COUNT(*) FROM $tbl WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) $extra";

$chgUsers  = pct_change($scalar($new('users', "AND role = 'user'")), $scalar($prev('users', "AND role = 'user'")));
$chgOrders = pct_change($scalar($new('orders')), $scalar($prev('orders')));
$revCur    = $scalar("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$revPrev   = $scalar("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
$chgRev    = pct_change($revCur, $revPrev);
$lowStock  = (int)$scalar("SELECT COUNT(*) FROM books WHERE status = 'active' AND stock <= 5");

/* ---------- Doanh thu 6 tháng gần nhất ---------- */
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $months[date('Y-m', strtotime("first day of -$i month"))] = 0.0;
}
$rows = $pdo->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, SUM(total_amount) AS rev
     FROM orders WHERE status = 'completed' AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
     GROUP BY ym"
)->fetchAll();
foreach ($rows as $r) {
    if (isset($months[$r['ym']])) { $months[$r['ym']] = (float)$r['rev']; }
}
$maxM = max($months) ?: 1;

/* ---------- Tình trạng đơn hàng ---------- */
$byStatus = $pdo->query('SELECT status, COUNT(*) FROM orders GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$done = (int)($byStatus['completed'] ?? 0);
$cancel = (int)($byStatus['cancelled'] ?? 0);
$proc = $totOrders - $done - $cancel;
$pc = static fn(int $n) => $totOrders > 0 ? round($n * 100 / $totOrders) : 0;

/* ---------- Đơn hàng gần đây ---------- */
$recent = $pdo->query(
    'SELECT o.id, o.order_code, o.receiver_name, o.total_amount, o.status, o.created_at, u.email
     FROM orders o JOIN users u ON u.id = o.user_id ORDER BY o.created_at DESC, o.id DESC LIMIT 6'
)->fetchAll();

admin_head('Tổng quan', 'dashboard', ['action' => 'orders.php', 'placeholder' => 'Tìm mã đơn, tên người nhận...']);
?>
<div class="a-title">
    <div><h1>Tổng quan</h1><p>Theo dõi hoạt động kinh doanh của nhà sách.</p></div>
    <a class="a-btn red" href="reports.php">Xem báo cáo</a>
</div>

<div class="a-cards">
    <div class="a-stat"><small>Khách hàng</small><div class="num"><?= number_format($totUsers, 0, ',', '.') ?></div>
        <?php if ($chgUsers): ?><span class="chg"><?= e($chgUsers) ?></span><span class="sub">so với 30 ngày trước</span><?php endif; ?></div>
    <div class="a-stat"><small>Đơn hàng</small><div class="num"><?= number_format($totOrders, 0, ',', '.') ?></div>
        <?php if ($chgOrders): ?><span class="chg"><?= e($chgOrders) ?></span><span class="sub">so với 30 ngày trước</span><?php endif; ?></div>
    <div class="a-stat"><small>Sản phẩm</small><div class="num"><?= number_format($totBooks, 0, ',', '.') ?></div>
        <span class="sub" style="margin:0"><?= $lowStock ?> sách sắp hết hàng (≤ 5)</span></div>
    <div class="a-stat"><small>Doanh thu (đơn hoàn thành)</small><div class="num"><?= e(fmt_short($totRevenue)) ?> đ</div>
        <?php if ($chgRev): ?><span class="chg"><?= e($chgRev) ?></span><span class="sub">so với 30 ngày trước</span><?php endif; ?></div>
</div>

<div class="a-grid3">
    <div class="a-card">
        <div class="a-card-head"><h3>Doanh thu 6 tháng gần nhất</h3></div>
        <div class="a-cbars">
            <?php $lastKey = array_key_last($months); foreach ($months as $ym => $rev): ?>
                <div class="col" title="<?= e(money($rev)) ?>">
                    <span><?= $rev > 0 ? e(fmt_short($rev)) : '' ?></span>
                    <div class="bar <?= $ym === $lastKey ? 'cur' : '' ?>" style="height: <?= max(2, round($rev / $maxM * 80)) ?>%"></div>
                    <span><?= e(date('m/Y', strtotime($ym . '-01'))) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="a-dark">
        <small>Hiệu suất vận hành</small>
        <div class="big"><?= number_format($totOrders, 0, ',', '.') ?> <small style="font-size:13px;font-weight:400">đơn</small></div>
        <div class="a-prog"><div class="t"><span>Hoàn thành</span><span><?= $pc($done) ?>%</span></div><div class="tr"><i style="width:<?= $pc($done) ?>%;background:#22c55e"></i></div></div>
        <div class="a-prog"><div class="t"><span>Đang xử lý</span><span><?= $pc($proc) ?>%</span></div><div class="tr"><i style="width:<?= $pc($proc) ?>%;background:#f59e0b"></i></div></div>
        <div class="a-prog"><div class="t"><span>Đã hủy</span><span><?= $pc($cancel) ?>%</span></div><div class="tr"><i style="width:<?= $pc($cancel) ?>%;background:#e11d2e"></i></div></div>
    </div>
</div>

<div class="a-card">
    <div class="a-card-head"><h3>Đơn hàng gần đây</h3><a href="orders.php">Xem tất cả đơn hàng ›</a></div>
    <div class="a-tablewrap">
        <table class="a-table">
            <thead><tr><th>Mã đơn</th><th>Khách hàng</th><th>Ngày đặt</th><th class="r">Tổng tiền</th><th>Trạng thái</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recent as $o): ?>
                <tr>
                    <td class="code"><a href="order_view.php?id=<?= (int)$o['id'] ?>">#<?= e($o['order_code']) ?></a></td>
                    <td><?= e($o['receiver_name']) ?><br><small class="muted"><?= e($o['email']) ?></small></td>
                    <td><?= e(date('d/m/Y H:i', strtotime($o['created_at']))) ?></td>
                    <td class="r"><strong><?= e(money($o['total_amount'])) ?></strong></td>
                    <td><?= status_badge($o['status']) ?></td>
                    <td class="act"><a class="a-ico" href="order_view.php?id=<?= (int)$o['id'] ?>" title="Xem chi tiết">👁</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?><tr><td colspan="6" class="a-empty">Chưa có đơn hàng nào.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php admin_foot(); ?>
