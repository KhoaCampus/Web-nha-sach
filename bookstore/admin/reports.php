<?php
require_once __DIR__ . '/_auth.php';
require_admin();
$pdo = db();

/* ---------- Khoảng thời gian (mặc định 30 ngày gần nhất, tối đa 366 ngày) ---------- */
$isDate = static fn(string $d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$to   = $_GET['to'] ?? date('Y-m-d');
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-29 days'));
if (!$isDate($to))   { $to = date('Y-m-d'); }
if (!$isDate($from)) { $from = date('Y-m-d', strtotime($to . ' -29 days')); }
if ($from > $to)     { [$from, $to] = [$to, $from]; }
if ((strtotime($to) - strtotime($from)) / 86400 > 365) { $from = date('Y-m-d', strtotime($to . ' -365 days')); }

$f = $from . ' 00:00:00';
$t = $to . ' 23:59:59';

/* ---------- Số liệu tổng ---------- */
$st = $pdo->prepare(
    "SELECT COUNT(*) AS orders,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount END), 0) AS revenue,
            SUM(status = 'completed') AS completed,
            SUM(status = 'cancelled') AS cancelled
     FROM orders WHERE created_at BETWEEN ? AND ?"
);
$st->execute([$f, $t]);
$sum = $st->fetch();

$st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'user' AND created_at BETWEEN ? AND ?");
$st->execute([$f, $t]);
$newUsers = (int)$st->fetchColumn();

$avgOrder = (int)$sum['completed'] > 0 ? (float)$sum['revenue'] / (int)$sum['completed'] : 0;

/* ---------- Theo ngày ---------- */
$st = $pdo->prepare(
    "SELECT DATE(created_at) AS d, COUNT(*) AS n,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount END), 0) AS rev
     FROM orders WHERE created_at BETWEEN ? AND ? GROUP BY d"
);
$st->execute([$f, $t]);
$byDay = [];
foreach ($st->fetchAll() as $r) { $byDay[$r['d']] = $r; }

$labels = []; $revs = []; $cnts = [];
for ($ts = strtotime($from); $ts <= strtotime($to); $ts += 86400) {
    $d = date('Y-m-d', $ts);
    $labels[] = date('d/m', $ts);
    $revs[]   = (float)($byDay[$d]['rev'] ?? 0);
    $cnts[]   = (int)($byDay[$d]['n'] ?? 0);
}

/* ---------- Sản phẩm bán chạy (đơn hoàn thành trong khoảng) ---------- */
$st = $pdo->prepare(
    "SELECT oi.book_id, oi.book_title, SUM(oi.quantity) AS qty, SUM(oi.quantity * oi.unit_price) AS revenue
     FROM order_items oi JOIN orders o ON o.id = oi.order_id
     WHERE o.status = 'completed' AND o.created_at BETWEEN ? AND ?
     GROUP BY oi.book_id, oi.book_title ORDER BY qty DESC, revenue DESC LIMIT 10"
);
$st->execute([$f, $t]);
$top = $st->fetchAll();

admin_head('Báo cáo / Thống kê', 'reports');
?>
<div class="a-title">
    <div><h1>Báo cáo / Thống kê</h1><p>Từ <?= e(date('d/m/Y', strtotime($from))) ?> đến <?= e(date('d/m/Y', strtotime($to))) ?></p></div>
</div>

<form class="a-filter" method="get" action="reports.php">
    <span class="muted">Từ</span><input type="date" name="from" value="<?= e($from) ?>" max="<?= e(date('Y-m-d')) ?>">
    <span class="muted">đến</span><input type="date" name="to" value="<?= e($to) ?>" max="<?= e(date('Y-m-d')) ?>">
    <button type="submit" class="a-btn red sm">Xem báo cáo</button>
    <a class="a-btn line sm" href="reports.php?from=<?= e(date('Y-m-d', strtotime('-6 days'))) ?>&to=<?= e(date('Y-m-d')) ?>">7 ngày</a>
    <a class="a-btn line sm" href="reports.php">30 ngày</a>
    <a class="a-btn line sm" href="reports.php?from=<?= e(date('Y-m-01')) ?>&to=<?= e(date('Y-m-d')) ?>">Tháng này</a>
</form>

<div class="a-cards">
    <div class="a-stat"><small>Đơn hàng đặt</small><div class="num"><?= number_format((int)$sum['orders'], 0, ',', '.') ?></div>
        <span class="sub" style="margin:0"><?= (int)$sum['completed'] ?> hoàn thành · <?= (int)$sum['cancelled'] ?> hủy</span></div>
    <div class="a-stat"><small>Doanh thu (đơn hoàn thành)</small><div class="num"><?= e(money($sum['revenue'])) ?></div></div>
    <div class="a-stat"><small>Giá trị trung bình / đơn</small><div class="num"><?= e(money($avgOrder)) ?></div></div>
    <div class="a-stat"><small>Khách hàng mới</small><div class="num"><?= $newUsers ?></div></div>
</div>

<div class="a-grid2" style="margin-bottom:16px">
    <div class="a-card">
        <h3>Xu hướng doanh thu</h3>
        <?= svg_line($labels, $revs) ?>
    </div>
    <div class="a-card">
        <h3>Số lượng đơn hàng theo ngày</h3>
        <?= svg_bars($labels, $cnts, 560, 230, '#e11d2e') ?>
    </div>
</div>

<div class="a-card">
    <h3>Sản phẩm bán chạy</h3>
    <div class="a-tablewrap">
        <table class="a-table">
            <thead><tr><th>#</th><th>Sản phẩm</th><th class="r">Đã bán</th><th class="r">Doanh thu</th></tr></thead>
            <tbody>
            <?php foreach ($top as $i => $b): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><a href="book_form.php?id=<?= (int)$b['book_id'] ?>"><?= e($b['book_title']) ?></a></td>
                    <td class="r"><?= (int)$b['qty'] ?></td>
                    <td class="r"><strong><?= e(money($b['revenue'])) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$top): ?><tr><td colspan="4" class="a-empty">Chưa có đơn hoàn thành trong khoảng thời gian này.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php admin_foot(); ?>
