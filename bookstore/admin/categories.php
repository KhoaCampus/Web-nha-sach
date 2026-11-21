<?php
require_once __DIR__ . '/_auth.php';
$admin = require_admin();
$pdo   = db();

$form   = ['id' => 0, 'name' => '', 'slug' => '', 'parent_id' => '0'];
$errors = [];

/* ---------- POST: lưu / xóa ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash_set('error', 'Phiên làm việc không hợp lệ, vui lòng thử lại.');
        redirect('categories.php');
    }
    $action = $_POST['action'] ?? '';
    $cid    = (int)($_POST['id'] ?? 0);

    $cur = null;
    if ($cid > 0) {
        $st = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
        $st->execute([$cid]);
        $cur = $st->fetch();
        if (!$cur) {
            flash_set('error', 'Không tìm thấy danh mục.');
            redirect('categories.php');
        }
    }

    /* ----- Xóa: chỉ khi danh mục trống ----- */
    if ($action === 'delete' && $cur) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE parent_id = ?');
        $st->execute([$cid]);
        $children = (int)$st->fetchColumn();

        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM book_categories bc JOIN books b ON b.id = bc.book_id AND b.status <> 'deleted' WHERE bc.category_id = ?"
        );
        $st->execute([$cid]);
        $books = (int)$st->fetchColumn();

        if ($children > 0) {
            flash_set('error', 'Danh mục "' . $cur['name'] . '" còn ' . $children . ' danh mục con. Hãy xóa hoặc chuyển các danh mục con trước.');
        } elseif ($books > 0) {
            flash_set('error', 'Danh mục "' . $cur['name'] . '" còn ' . $books . ' sách. Hãy chuyển các sách sang danh mục khác trước khi xóa.');
        } else {
            $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$cid]);
            audit_log((int)$admin['id'], 'admin_category_delete', 'categories', $cid, $cur['name']);
            flash_set('success', 'Đã xóa danh mục "' . $cur['name'] . '".');
        }
        redirect('categories.php');
    }

    /* ----- Lưu (thêm / sửa) ----- */
    if ($action === 'save') {
        $form['id']        = $cid;
        $form['name']      = trim($_POST['name'] ?? '');
        $form['slug']      = trim(mb_strtolower($_POST['slug'] ?? ''));
        $form['parent_id'] = (string)(int)($_POST['parent_id'] ?? 0);

        if ($form['name'] === '' || mb_strlen($form['name']) > 100) {
            $errors[] = 'Tên danh mục không được để trống và tối đa 100 ký tự.';
        }

        // đường dẫn (slug): tự tạo từ tên nếu để trống
        $slugAuto = ($form['slug'] === '');
        if (!$slugAuto && (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $form['slug']) || strlen($form['slug']) > 120)) {
            $errors[] = 'Đường dẫn chỉ gồm chữ thường không dấu, số và dấu gạch ngang (tối đa 120 ký tự).';
        }

        // danh mục cha: chỉ 2 cấp (cha -> con), vì trang bán hàng lọc theo 2 cấp
        $parentId = (int)$form['parent_id'];
        if ($parentId > 0) {
            $st = $pdo->prepare('SELECT id, parent_id FROM categories WHERE id = ?');
            $st->execute([$parentId]);
            $par = $st->fetch();
            if (!$par) {
                $errors[] = 'Danh mục cha không tồn tại.';
            } elseif ($par['parent_id'] !== null) {
                $errors[] = 'Chỉ hỗ trợ 2 cấp: danh mục cha phải là danh mục cấp 1.';
            } elseif ($cid > 0 && $parentId === $cid) {
                $errors[] = 'Danh mục không thể là cha của chính nó.';
            }
            if ($cid > 0) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE parent_id = ?');
                $st->execute([$cid]);
                if ((int)$st->fetchColumn() > 0) {
                    $errors[] = 'Danh mục đang có danh mục con nên phải giữ ở cấp 1.';
                }
            }
        }

        if (!$errors) {
            // slug tự tạo: thêm hậu tố -2, -3... cho đến khi không trùng
            if ($slugAuto) {
                $base = mb_substr(slugify($form['name']), 0, 110);
                $slug = $base;
                $chk  = $pdo->prepare('SELECT 1 FROM categories WHERE slug = ? AND id <> ?');
                for ($i = 2; ; $i++) {
                    $chk->execute([$slug, $cid]);
                    if (!$chk->fetchColumn()) { break; }
                    $slug = $base . '-' . $i;
                }
            } else {
                $slug = $form['slug'];
                $chk  = $pdo->prepare('SELECT 1 FROM categories WHERE slug = ? AND id <> ?');
                $chk->execute([$slug, $cid]);
                if ($chk->fetchColumn()) { $errors[] = 'Đường dẫn "' . $slug . '" đã được dùng cho danh mục khác.'; }
            }
        }

        if (!$errors) {
            try {
                $pidVal = $parentId > 0 ? $parentId : null;
                if ($cur) {
                    $pdo->prepare('UPDATE categories SET name = ?, slug = ?, parent_id = ? WHERE id = ?')->execute([$form['name'], $slug, $pidVal, $cid]);
                } else {
                    $pdo->prepare('INSERT INTO categories (name, slug, parent_id) VALUES (?, ?, ?)')->execute([$form['name'], $slug, $pidVal]);
                    $cid = (int)$pdo->lastInsertId();
                }
                audit_log((int)$admin['id'], $cur ? 'admin_category_update' : 'admin_category_create', 'categories', $cid, $form['name']);
                flash_set('success', $cur ? 'Đã cập nhật danh mục.' : 'Đã thêm danh mục "' . $form['name'] . '".');
                redirect('categories.php');
            } catch (PDOException $ex) {
                $errors[] = ($ex->getCode() === '23000') ? 'Đường dẫn hoặc tên danh mục bị trùng, vui lòng kiểm tra lại.' : 'Có lỗi hệ thống, vui lòng thử lại.';
                if ($ex->getCode() !== '23000') { error_log('categories: ' . $ex->getMessage()); }
            }
        }
    }
} elseif (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    if ($c = $st->fetch()) {
        $form = ['id' => (int)$c['id'], 'name' => $c['name'], 'slug' => $c['slug'], 'parent_id' => (string)(int)$c['parent_id']];
    }
}

/* ---------- Dữ liệu hiển thị: cây 2 cấp + số sách ---------- */
$all = $pdo->query(
    "SELECT c.id, c.name, c.slug, c.parent_id,
            (SELECT COUNT(*) FROM book_categories bc JOIN books b ON b.id = bc.book_id AND b.status <> 'deleted' WHERE bc.category_id = c.id) AS books
     FROM categories c ORDER BY c.parent_id IS NOT NULL, c.parent_id, c.id"
)->fetchAll();

$tops = [];
$kids = [];
foreach ($all as $c) {
    if ($c['parent_id'] === null) { $tops[(int)$c['id']] = $c; }
    else { $kids[(int)$c['parent_id']][] = $c; }
}
$rows = [];
foreach ($tops as $t) {
    $rows[] = [$t, 0];
    foreach ($kids[(int)$t['id']] ?? [] as $k) { $rows[] = [$k, 1]; }
}
// danh mục con mồ côi (cha đã bị xóa -> parent_id NULL nên không xảy ra); phòng dữ liệu lạ
$showForm = $errors || isset($_GET['edit']) || isset($_GET['new']);

admin_head('Quản lý danh mục', 'categories');
?>
<div class="a-title">
    <div><h1>Quản lý danh mục</h1><p><?= count($tops) ?> danh mục cấp 1 · <?= count($all) - count($tops) ?> danh mục con</p></div>
    <?php if (!$showForm): ?><a class="a-btn red" href="categories.php?new=1">+ Thêm danh mục</a><?php endif; ?>
</div>

<?php if ($showForm): ?>
<div class="a-card">
    <h3><?= $form['id'] ? 'Sửa danh mục' : 'Thêm danh mục' ?></h3>
    <?php if ($errors): ?><div class="a-alert error"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form class="a-form" method="post" action="categories.php" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
        <div class="row3">
            <div><label for="name">Tên danh mục *</label><input type="text" id="name" name="name" maxlength="100" autofocus value="<?= e($form['name']) ?>"></div>
            <div><label for="slug">Đường dẫn (slug)</label><input type="text" id="slug" name="slug" maxlength="120" placeholder="Để trống để tự tạo từ tên" value="<?= e($form['slug']) ?>"></div>
            <div><label for="parent_id">Danh mục cha</label>
                <select id="parent_id" name="parent_id">
                    <option value="0">— Không (danh mục cấp 1) —</option>
                    <?php foreach ($tops as $t): if ((int)$t['id'] === (int)$form['id']) { continue; } ?>
                        <option value="<?= (int)$t['id'] ?>" <?= (string)$t['id'] === $form['parent_id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
                    <?php endforeach; ?>
                </select></div>
        </div>
        <div class="actions"><button type="submit" class="a-btn red">Lưu danh mục</button><a class="a-btn line" href="categories.php">Hủy</a></div>
    </form>
</div>
<?php endif; ?>

<div class="a-card">
    <div class="a-tablewrap">
        <table class="a-table">
            <thead><tr><th>ID</th><th>Tên danh mục</th><th>Đường dẫn</th><th>Danh mục cha</th><th class="r">Số sách</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as [$c, $level]): ?>
                <tr>
                    <td class="muted"><?= (int)$c['id'] ?></td>
                    <td style="<?= $level ? 'padding-left:32px' : 'font-weight:700' ?>"><?= $level ? '↳ ' : '' ?><?= e($c['name']) ?></td>
                    <td class="muted"><?= e($c['slug']) ?></td>
                    <td><?= $level ? e($tops[(int)$c['parent_id']]['name'] ?? '—') : '<span class="muted">—</span>' ?></td>
                    <td class="r"><?= (int)$c['books'] ?></td>
                    <td class="act">
                        <a class="a-ico" href="categories.php?edit=<?= (int)$c['id'] ?>" title="Sửa">✏️</a>
                        <form method="post" action="categories.php" data-msg="<?= e('Xóa danh mục "' . $c['name'] . '"?') ?>" onsubmit="return confirm(this.dataset.msg)">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button class="a-ico" type="submit" title="Xóa">🗑</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="a-empty">Chưa có danh mục nào.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="muted" style="margin:12px 0 0;font-size:12px">Chỉ hỗ trợ 2 cấp. Chỉ xóa được danh mục không còn danh mục con và không còn sách.</p>
</div>
<?php admin_foot(); ?>
