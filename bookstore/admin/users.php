<?php
require_once __DIR__ . '/_auth.php';
$admin = require_admin();
$pdo   = db();

/* ---------- POST: khóa / mở khóa / xóa mềm ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash_set('error', 'Phiên làm việc không hợp lệ, vui lòng thử lại.');
        redirect('users.php');
    }
    $uid    = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $st = $pdo->prepare("SELECT id, full_name, role, status FROM users WHERE id = ? AND status <> 'deleted'");
    $st->execute([$uid]);
    $u = $st->fetch();

    if (!$u) {
        flash_set('error', 'Không tìm thấy khách hàng.');
    } elseif ($u['role'] !== 'user' || (int)$u['id'] === (int)$admin['id']) {
        flash_set('error', 'Không thể thao tác trên tài khoản quản trị.');
    } elseif ($action === 'lock') {
        $pdo->prepare("UPDATE users SET status = 'locked' WHERE id = ?")->execute([$uid]);
        audit_log((int)$admin['id'], 'admin_user_lock', 'users', $uid);
        flash_set('success', 'Đã khóa tài khoản ' . $u['full_name'] . '. Khách sẽ bị đăng xuất ngay ở lần truy cập tiếp theo.');
    } elseif ($action === 'unlock') {
        $pdo->prepare("UPDATE users SET status = 'active', failed_login_count = 0, locked_until = NULL WHERE id = ?")->execute([$uid]);
        audit_log((int)$admin['id'], 'admin_user_unlock', 'users', $uid);
        flash_set('success', 'Đã mở khóa tài khoản ' . $u['full_name'] . '.');
    } elseif ($action === 'delete') {
        // xóa mềm: giữ lịch sử đơn hàng; tài khoản không đăng nhập được nữa
        $pdo->prepare("UPDATE users SET status = 'deleted' WHERE id = ?")->execute([$uid]);
        audit_log((int)$admin['id'], 'admin_user_delete', 'users', $uid);
        flash_set('success', 'Đã xóa tài khoản ' . $u['full_name'] . '.');
    }
    redirect('users.php' . (!empty($_POST['back']) ? '?' . ltrim((string)$_POST['back'], '?') : ''));
}

/* ---------- Bộ lọc ---------- */
$q      = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
if (!in_array($status, ['active', 'locked'], true)) { $status = ''; }

$where  = ["u.status <> 'deleted'"];
$params = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($status !== '') { $where[] = 'u.status = ?'; $params[] = $status; }
$whereSql = implode(' AND ', $where);

$perPage = 12;
$st = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
$st->execute($params);
$total      = (int)$st->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
$offset     = ($page - 1) * $perPage;

$st = $pdo->prepare(
    "SELECT u.id, u.full_name, u.email, u.phone, u.role, u.status, u.created_at, u.last_login_at,
            (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS orders,
            (SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o WHERE o.user_id = u.id AND o.status = 'completed') AS spent
     FROM users u WHERE $whereSql ORDER BY u.created_at DESC, u.id DESC LIMIT $perPage OFFSET $offset"
);
$st->execute($params);
$users = $st->fetchAll();
$back = http_build_query(array_filter(['q' => $q, 'status' => $status, 'page' => $page > 1 ? $page : null]));

admin_head('Quản lý khách hàng', 'users', ['action' => 'users.php', 'placeholder' => 'Tìm tên, email, số điện thoại...']);
?>
<div class="a-title">
    <div><h1>Quản lý khách hàng</h1><p><?= $total ?> tài khoản</p></div>
</div>

<form class="a-filter" method="get" action="users.php">
    <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
    <select name="status" onchange="this.form.submit()">
        <option value="">Tất cả trạng thái</option>
        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Đang hoạt động</option>
        <option value="locked" <?= $status === 'locked' ? 'selected' : '' ?>>Đã khóa</option>
    </select>
    <?php if ($q !== '' || $status !== ''): ?><a class="a-btn line sm" href="users.php">Xóa lọc</a><?php endif; ?>
</form>

<div class="a-card">
    <div class="a-tablewrap">
        <table class="a-table">
            <thead><tr><th>ID</th><th>Khách hàng</th><th>Liên hệ</th><th>Ngày đăng ký</th><th class="r">Đơn hàng</th><th class="r">Đã chi tiêu</th><th>Trạng thái</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): $isMe = (int)$u['id'] === (int)$admin['id']; $canAct = $u['role'] === 'user' && !$isMe; ?>
                <tr>
                    <td class="muted"><?= (int)$u['id'] ?></td>
                    <td><div class="a-user-cell"><span class="a-avatar"><?= e(mb_strtoupper(mb_substr($u['full_name'], 0, 1))) ?></span>
                        <div><strong><?= e($u['full_name']) ?></strong><?php if ($u['role'] === 'admin'): ?> <span class="a-badge st-shipping">Admin</span><?php endif; ?></div></div></td>
                    <td><?= e($u['email']) ?><br><small class="muted"><?= e($u['phone'] ?? 'Chưa có SĐT') ?></small></td>
                    <td><?= e(date('d/m/Y', strtotime($u['created_at']))) ?><br><small class="muted"><?= $u['last_login_at'] ? 'Đăng nhập ' . e(date('d/m/Y', strtotime($u['last_login_at']))) : 'Chưa đăng nhập' ?></small></td>
                    <td class="r"><?= (int)$u['orders'] ?></td>
                    <td class="r"><?= e(money($u['spent'])) ?></td>
                    <td><span class="a-badge <?= $u['status'] === 'active' ? 'ok' : 'bad' ?>"><?= $u['status'] === 'active' ? 'Hoạt động' : 'Đã khóa' ?></span></td>
                    <td class="act">
                        <?php if ($canAct): ?>
                            <?php if ($u['orders'] > 0): ?><a class="a-ico" href="orders.php?q=<?= urlencode($u['email']) ?>" title="Xem đơn hàng">🧾</a><?php endif; ?>
                            <form method="post" action="users.php" <?= $u['status'] === 'active' ? 'onsubmit="return confirm(\'Khóa tài khoản này?\')"' : '' ?>>
                                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
                                <input type="hidden" name="action" value="<?= $u['status'] === 'active' ? 'lock' : 'unlock' ?>">
                                <button class="a-ico" type="submit" title="<?= $u['status'] === 'active' ? 'Khóa' : 'Mở khóa' ?>"><?= $u['status'] === 'active' ? '🔒' : '🔓' ?></button>
                            </form>
                            <form method="post" action="users.php" onsubmit="return confirm('Xóa tài khoản này? Lịch sử đơn hàng vẫn được giữ.')">
                                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
                                <input type="hidden" name="action" value="delete">
                                <button class="a-ico" type="submit" title="Xóa">🗑</button>
                            </form>
                        <?php else: ?><small class="muted"><?= $isMe ? 'Bạn' : '—' ?></small><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$users): ?><tr><td colspan="8" class="a-empty">Không có khách hàng nào.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= pager_html($page, $totalPages) ?>
</div>
<?php admin_foot(); ?>
