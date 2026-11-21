<?php
require_once __DIR__ . '/_auth.php';
$admin = require_admin();
$pdo   = db();
$id    = (int)($_GET['id'] ?? 0);

/* ---------- POST: đổi trạng thái / xác nhận đã thanh toán ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? $id);
    if (!csrf_valid()) {
        flash_set('error', 'Phiên làm việc không hợp lệ, vui lòng thử lại.');
        redirect('order_view.php?id=' . $id);
    }
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'status') {
            $to   = $_POST['to_status'] ?? '';
            $note = trim($_POST['note'] ?? '');
            if (mb_strlen($note) > 255) {
                throw new RuntimeException('Ghi chú tối đa 255 ký tự.');
            }
            flash_set('success', admin_change_order_status($pdo, $id, $to, (int)$admin['id'], $note));
        } elseif ($action === 'mark_paid') {
            $st = $pdo->prepare("SELECT o.status FROM orders o WHERE o.id = ?");
            $st->execute([$id]);
            $os = $st->fetchColumn();
            if ($os === false || $os === 'cancelled') {
                throw new RuntimeException('Không thể xác nhận thanh toán cho đơn này.');
            }
            $upd = $pdo->prepare("UPDATE payments SET status = 'paid', paid_at = NOW() WHERE order_id = ? AND status = 'pending'");
            $upd->execute([$id]);
            if ($upd->rowCount() === 0) {
                throw new RuntimeException('Đơn này không có khoản thanh toán nào đang chờ.');
            }
            audit_log((int)$admin['id'], 'admin_mark_paid', 'orders', $id);
            flash_set('success', 'Đã xác nhận khách thanh toán.');
        }
    } catch (RuntimeException $ex) {
        flash_set('error', $ex->getMessage());
    } catch (PDOException $ex) {
        error_log('order_view: ' . $ex->getMessage());
        flash_set('error', 'Có lỗi hệ thống, vui lòng thử lại.');
    }
    redirect('order_view.php?id=' . $id);
}

/* ---------- Tải đơn ---------- */
$st = $pdo->prepare(
    'SELECT o.*, u.email, u.full_name AS account_name, c.code AS coupon_code
     FROM orders o JOIN users u ON u.id = o.user_id LEFT JOIN coupons c ON c.id = o.coupon_id
     WHERE o.id = ?'
);
$st->execute([$id]);
$o = $st->fetch();
if (!$o) {
    flash_set('error', 'Không tìm thấy đơn hàng.');
    redirect('orders.php');
}

$st = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
$st->execute([$id]);
$items = $st->fetchAll();

$st = $pdo->prepare('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1');
$st->execute([$id]);
$pay = $st->fetch();

$st = $pdo->prepare(
    'SELECT h.*, u.full_name FROM order_status_history h LEFT JOIN users u ON u.id = h.changed_by
     WHERE h.order_id = ? ORDER BY h.id DESC'
);
$st->execute([$id]);
$history = $st->fetchAll();

$labels    = $GLOBALS['ORDER_STATUS'];
$next      = allowed_next_statuses($o['status']);
$payLabels = ['pending' => 'Chưa thanh toán', 'paid' => 'Đã thanh toán', 'failed' => 'Không thành công', 'refunded' => 'Đã hoàn tiền'];
$payClass  = ['pending' => 'warn', 'paid' => 'ok', 'failed' => 'bad', 'refunded' => 'off'];

admin_head('Đơn hàng ' . $o['order_code'], 'orders');
?>
<div class="a-title">
    <div>
        <p><a href="orders.php">← Danh sách đơn hàng</a></p>
        <h1>Chi tiết đơn hàng #<?= e($o['order_code']) ?></h1>
        <p>Đặt lúc <?= e(date('H:i d/m/Y', strtotime($o['created_at']))) ?></p>
    </div>
    <?= status_badge($o['status']) ?>
</div>

<div class="a-grid3">
    <div>
        <div class="a-card">
            <h3>Sản phẩm</h3>
            <div class="a-tablewrap">
                <table class="a-table">
                    <thead><tr><th>Sản phẩm</th><th class="r">Đơn giá</th><th class="r">SL</th><th class="r">Thành tiền</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $it): ?>
                        <tr>
                            <td><?= e($it['book_title']) ?></td>
                            <td class="r"><?= e(money($it['unit_price'])) ?></td>
                            <td class="r"><?= (int)$it['quantity'] ?></td>
                            <td class="r"><strong><?= e(money((float)$it['unit_price'] * (int)$it['quantity'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="a-sum">
                <div><span>Tạm tính</span><span><?= e(money($o['subtotal'])) ?></span></div>
                <div><span>Giảm giá<?= $o['coupon_code'] ? ' (' . e($o['coupon_code']) . ')' : '' ?></span>
                    <span><?= (float)$o['discount_amount'] > 0 ? '- ' . e(money($o['discount_amount'])) : '0 đ' ?></span></div>
                <div><span>Phí vận chuyển</span><span><?= (float)$o['shipping_fee'] == 0 ? 'Miễn phí' : e(money($o['shipping_fee'])) ?></span></div>
                <div class="grand"><span>Tổng cộng</span><span><?= e(money($o['total_amount'])) ?></span></div>
            </div>
        </div>

        <div class="a-grid2" style="margin-bottom:16px">
            <div class="a-card">
                <h3>Khách hàng</h3>
                <dl class="a-kv">
                    <dt>Người nhận</dt><dd><?= e($o['receiver_name']) ?></dd>
                    <dt>Điện thoại</dt><dd><?= e($o['phone']) ?></dd>
                    <dt>Tài khoản</dt><dd><?= e($o['account_name']) ?><br><small class="muted"><?= e($o['email']) ?></small></dd>
                    <dt>Địa chỉ</dt><dd><?= e($o['shipping_address']) ?></dd>
                    <?php if ($o['note']): ?><dt>Ghi chú</dt><dd><?= e($o['note']) ?></dd><?php endif; ?>
                </dl>
            </div>
            <div class="a-card">
                <h3>Thanh toán</h3>
                <?php if ($pay): ?>
                    <dl class="a-kv">
                        <dt>Phương thức</dt><dd><?= e($GLOBALS['PAYMENT_METHODS'][$pay['method']] ?? $pay['method']) ?></dd>
                        <dt>Số tiền</dt><dd><?= e(money($pay['amount'])) ?></dd>
                        <dt>Trạng thái</dt><dd><span class="a-badge <?= e($payClass[$pay['status']] ?? '') ?>"><?= e($payLabels[$pay['status']] ?? $pay['status']) ?></span></dd>
                        <?php if ($pay['paid_at']): ?><dt>Thanh toán lúc</dt><dd><?= e(date('H:i d/m/Y', strtotime($pay['paid_at']))) ?></dd><?php endif; ?>
                    </dl>
                    <?php if ($pay['status'] === 'pending' && $o['status'] !== 'cancelled'): ?>
                        <form method="post" action="order_view.php" onsubmit="return confirm('Xác nhận khách đã thanh toán đơn này?')">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="mark_paid">
                            <button type="submit" class="a-btn line sm" style="margin-top:12px">Xác nhận đã thanh toán</button>
                        </form>
                    <?php endif; ?>
                <?php else: ?><p class="muted">Chưa có thông tin thanh toán.</p><?php endif; ?>
            </div>
        </div>
    </div>

    <div>
        <div class="a-card">
            <h3>Trạng thái đơn hàng</h3>
            <?php if ($next): ?>
                <form class="a-form" method="post" action="order_view.php" onsubmit="return confirmStatus(this)">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="status">
                    <label for="to_status">Chuyển sang</label>
                    <select name="to_status" id="to_status">
                        <?php foreach ($next as $s): ?><option value="<?= e($s) ?>"><?= e($labels[$s]) ?></option><?php endforeach; ?>
                    </select>
                    <label for="note">Ghi chú (không bắt buộc)</label>
                    <input type="text" name="note" id="note" maxlength="255" placeholder="VD: Đã gọi xác nhận với khách">
                    <div class="actions"><button type="submit" class="a-btn red">Lưu trạng thái</button></div>
                </form>
                <p class="hint muted" style="margin-top:10px;font-size:12px">Hủy đơn sẽ hoàn kho, hoàn lượt dùng mã khuyến mãi và cập nhật thanh toán. Hoàn thành đơn sẽ ghi nhận đã thanh toán.</p>
            <?php else: ?>
                <p class="muted">Đơn ở trạng thái cuối (<?= e($labels[$o['status']] ?? $o['status']) ?>), không thể đổi tiếp.</p>
            <?php endif; ?>
        </div>

        <div class="a-card">
            <h3>Lịch sử trạng thái</h3>
            <ul class="a-timeline">
                <?php foreach ($history as $h): ?>
                    <li>
                        <strong><?= e($labels[$h['to_status']] ?? $h['to_status']) ?></strong><br>
                        <small class="muted"><?= e(date('H:i d/m/Y', strtotime($h['created_at']))) ?><?= $h['full_name'] ? ' · ' . e($h['full_name']) : '' ?></small>
                        <?php if ($h['note']): ?><div class="muted"><?= e($h['note']) ?></div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php if (!$history): ?><li class="muted">Chưa có lịch sử.</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<script>
function confirmStatus(f) {
    var s = f.to_status;
    var label = s.options[s.selectedIndex].text;
    return confirm('Chuyển đơn sang "' + label + '"?');
}
</script>
<?php admin_foot(); ?>
