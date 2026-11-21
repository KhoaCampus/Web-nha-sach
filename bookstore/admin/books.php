<?php
require_once __DIR__ . '/_auth.php';
$admin = require_admin();
$pdo   = db();

/* ---------- POST: ẩn/hiện, xóa mềm ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash_set('error', 'Phiên làm việc không hợp lệ, vui lòng thử lại.');
        redirect('books.php');
    }
    $bid    = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $st = $pdo->prepare("SELECT id, title, status FROM books WHERE id = ? AND status <> 'deleted'");
    $st->execute([$bid]);
    $b = $st->fetch();
    if (!$b) {
        flash_set('error', 'Không tìm thấy sản phẩm.');
        redirect('books.php');
    }

    if ($action === 'toggle') {
        $new = $b['status'] === 'active' ? 'hidden' : 'active';
        $pdo->prepare('UPDATE books SET status = ? WHERE id = ?')->execute([$new, $bid]);
        audit_log((int)$admin['id'], 'admin_book_' . $new, 'books', $bid);
        flash_set('success', ($new === 'active' ? 'Đã hiện' : 'Đã ẩn') . ' "' . $b['title'] . '".');
    } elseif ($action === 'delete') {
        // xóa mềm: giữ lại để các đơn hàng cũ vẫn tham chiếu được (order_items.book_id)
        $pdo->prepare("UPDATE books SET status = 'deleted' WHERE id = ?")->execute([$bid]);
        $pdo->prepare('DELETE FROM cart_items WHERE book_id = ?')->execute([$bid]);
        audit_log((int)$admin['id'], 'admin_book_delete', 'books', $bid, $b['title']);
        flash_set('success', 'Đã xóa "' . $b['title'] . '".');
    }
    redirect('books.php' . (!empty($_POST['back']) ? '?' . ltrim((string)$_POST['back'], '?') : ''));
}

/* ---------- Bộ lọc ---------- */
$q      = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
if (!in_array($status, ['active', 'hidden'], true)) { $status = ''; }

$where  = ["b.status <> 'deleted'"];
$params = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(b.title LIKE ? OR b.isbn LIKE ? OR EXISTS (
                    SELECT 1 FROM book_authors ba JOIN authors a ON a.id = ba.author_id WHERE ba.book_id = b.id AND a.name LIKE ?))';
    array_push($params, $like, $like, $like);
}
if ($status !== '') { $where[] = 'b.status = ?'; $params[] = $status; }
$whereSql = implode(' AND ', $where);

$perPage = 12;
$st = $pdo->prepare("SELECT COUNT(*) FROM books b WHERE $whereSql");
$st->execute($params);
$total      = (int)$st->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
$offset     = ($page - 1) * $perPage;

$st = $pdo->prepare(
    "SELECT b.id, b.title, b.slug, b.cover_image, b.isbn, b.price, b.sale_price, b.stock, b.status, b.sold_count,
            (SELECT GROUP_CONCAT(a.name SEPARATOR ', ') FROM book_authors ba JOIN authors a ON a.id = ba.author_id WHERE ba.book_id = b.id) AS authors
     FROM books b WHERE $whereSql ORDER BY b.created_at DESC, b.id DESC LIMIT $perPage OFFSET $offset"
);
$st->execute($params);
$books = $st->fetchAll();
$back = http_build_query(array_filter(['q' => $q, 'status' => $status, 'page' => $page > 1 ? $page : null]));

admin_head('Quản lý sản phẩm', 'books', ['action' => 'books.php', 'placeholder' => 'Tìm tên sách, tác giả, ISBN...']);
?>
<div class="a-title">
    <div><h1>Quản lý sản phẩm</h1><p><?= $total ?> sản phẩm</p></div>
    <a class="a-btn red" href="book_form.php">+ Thêm sản phẩm</a>
</div>

<form class="a-filter" method="get" action="books.php">
    <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
    <select name="status" onchange="this.form.submit()">
        <option value="">Tất cả trạng thái</option>
        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Đang bán</option>
        <option value="hidden" <?= $status === 'hidden' ? 'selected' : '' ?>>Đang ẩn</option>
    </select>
    <?php if ($q !== '' || $status !== ''): ?><a class="a-btn line sm" href="books.php">Xóa lọc</a><?php endif; ?>
</form>

<div class="a-card">
    <div class="a-tablewrap">
        <table class="a-table">
            <thead><tr><th>ID</th><th>Sản phẩm</th><th class="r">Giá</th><th class="r">Tồn kho</th><th class="r">Đã bán</th><th>Trạng thái</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($books as $b): ?>
                <tr>
                    <td class="muted"><?= (int)$b['id'] ?></td>
                    <td><div class="a-prod"><?= cover_html($b, 'a-thumb', '../', 0) ?><div>
                        <a href="book_form.php?id=<?= (int)$b['id'] ?>"><strong><?= e($b['title']) ?></strong></a><br>
                        <small class="muted"><?= e($b['authors'] ?? 'Chưa có tác giả') ?><?= $b['isbn'] ? ' · ISBN ' . e($b['isbn']) : '' ?></small></div></div></td>
                    <td class="r">
                        <?php $fp = final_price($b); ?>
                        <strong><?= e(money($fp)) ?></strong>
                        <?php if ($fp < (float)$b['price']): ?><br><small class="muted"><s><?= e(money($b['price'])) ?></s></small><?php endif; ?>
                    </td>
                    <td class="r"><?= (int)$b['stock'] === 0 ? '<span class="a-badge bad">Hết hàng</span>' : ((int)$b['stock'] <= 5 ? '<span class="a-badge warn">' . (int)$b['stock'] . '</span>' : (int)$b['stock']) ?></td>
                    <td class="r"><?= (int)$b['sold_count'] ?></td>
                    <td><span class="a-badge <?= $b['status'] === 'active' ? 'ok' : 'off' ?>"><?= $b['status'] === 'active' ? 'Đang bán' : 'Đang ẩn' ?></span></td>
                    <td class="act">
                        <a class="a-ico" href="book_form.php?id=<?= (int)$b['id'] ?>" title="Sửa">✏️</a>
                        <form method="post" action="books.php">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="back" value="<?= e($back) ?>">
                            <button class="a-ico" type="submit" title="<?= $b['status'] === 'active' ? 'Ẩn sản phẩm' : 'Hiện sản phẩm' ?>"><?= $b['status'] === 'active' ? '🙈' : '👁' ?></button>
                        </form>
                        <form method="post" action="books.php" onsubmit="return confirm('Xóa sản phẩm này? Các đơn hàng cũ vẫn được giữ nguyên.')">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="back" value="<?= e($back) ?>">
                            <button class="a-ico" type="submit" title="Xóa">🗑</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$books): ?><tr><td colspan="7" class="a-empty">Không có sản phẩm nào.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= pager_html($page, $totalPages) ?>
</div>
<?php admin_foot(); ?>
