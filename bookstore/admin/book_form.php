<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../upload_lib.php';
$admin = require_admin();
$pdo   = db();

$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$book = null;
if ($id > 0) {
    $st = $pdo->prepare("SELECT * FROM books WHERE id = ? AND status <> 'deleted'");
    $st->execute([$id]);
    $book = $st->fetch();
    if (!$book) {
        flash_set('error', 'Không tìm thấy sản phẩm.');
        redirect('books.php');
    }
}

$publishers = $pdo->query('SELECT id, name FROM publishers ORDER BY name')->fetchAll();
$allCats    = $pdo->query('SELECT id, name, parent_id FROM categories ORDER BY parent_id IS NOT NULL, parent_id, id')->fetchAll();
$validCats  = array_map('intval', array_column($allCats, 'id'));

/* ---------- Giá trị ban đầu của form ---------- */
$form = [
    'title' => $book['title'] ?? '', 'isbn' => (string)($book['isbn'] ?? ''), 'description' => (string)($book['description'] ?? ''),
    'price' => isset($book) ? (string)(int)$book['price'] : '', 'sale_price' => isset($book) && $book['sale_price'] !== null ? (string)(int)$book['sale_price'] : '',
    'stock' => isset($book) ? (string)(int)$book['stock'] : '0', 'pages' => (string)($book['pages'] ?? ''), 'publish_year' => (string)($book['publish_year'] ?? ''),
    'publisher_id' => (string)($book['publisher_id'] ?? '0'), 'publisher_new' => '', 'status' => $book['status'] ?? 'active', 'authors' => '',
];
$selCats = [];
if ($book) {
    $st = $pdo->prepare('SELECT a.name FROM book_authors ba JOIN authors a ON a.id = ba.author_id WHERE ba.book_id = ? ORDER BY a.name');
    $st->execute([$id]);
    $form['authors'] = implode(', ', $st->fetchAll(PDO::FETCH_COLUMN));
    $st = $pdo->prepare('SELECT category_id FROM book_categories WHERE book_id = ?');
    $st->execute([$id]);
    $selCats = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

$errors = [];

/* ---------- POST: lưu ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $errors[] = 'Phiên làm việc không hợp lệ, vui lòng thử lại.';
    }
    foreach (['title', 'isbn', 'description', 'price', 'sale_price', 'stock', 'pages', 'publish_year', 'publisher_id', 'publisher_new', 'status', 'authors'] as $k) {
        $form[$k] = trim((string)($_POST[$k] ?? ''));
    }
    // ô giá cho phép gõ 81.000 hoặc 81,000
    foreach (['price', 'sale_price'] as $k) { $form[$k] = preg_replace('/[.,\s]/', '', $form[$k]); }
    $selCats = array_values(array_intersect(array_map('intval', (array)($_POST['cats'] ?? [])), $validCats));

    if ($form['title'] === '' || mb_strlen($form['title']) > 255) { $errors[] = 'Tên sách không được để trống và tối đa 255 ký tự.'; }
    if ($form['isbn'] !== '' && !preg_match('/^[0-9Xx-]{10,20}$/', $form['isbn'])) { $errors[] = 'ISBN chỉ gồm số, dấu - hoặc X (10–20 ký tự).'; }
    if (!preg_match('/^\d{1,10}$/', $form['price'])) { $errors[] = 'Giá bán phải là số nguyên không âm (tối đa 10 chữ số).'; }
    if ($form['sale_price'] !== '' && (!preg_match('/^\d{1,10}$/', $form['sale_price']) || (preg_match('/^\d+$/', $form['price']) && (int)$form['sale_price'] > (int)$form['price']))) {
        $errors[] = 'Giá khuyến mãi phải là số và không được lớn hơn giá bán.';
    }
    if (!preg_match('/^\d{1,7}$/', $form['stock'])) { $errors[] = 'Tồn kho phải là số nguyên không âm.'; }
    if ($form['pages'] !== '' && (!ctype_digit($form['pages']) || (int)$form['pages'] < 1 || (int)$form['pages'] > 65535)) { $errors[] = 'Số trang phải từ 1 đến 65535.'; }
    if ($form['publish_year'] !== '' && (!ctype_digit($form['publish_year']) || (int)$form['publish_year'] < 1901 || (int)$form['publish_year'] > 2155)) { $errors[] = 'Năm xuất bản phải từ 1901 đến 2155.'; }
    if (!in_array($form['status'], ['active', 'hidden'], true)) { $errors[] = 'Trạng thái không hợp lệ.'; }
    if (mb_strlen($form['description']) > 20000) { $errors[] = 'Mô tả tối đa 20.000 ký tự.'; }
    if (mb_strlen($form['publisher_new']) > 150) { $errors[] = 'Tên nhà xuất bản tối đa 150 ký tự.'; }

    $authorNames = [];
    foreach (explode(',', $form['authors']) as $nm) {
        $nm = trim($nm);
        if ($nm !== '' && !isset($authorNames[mb_strtolower($nm)])) { $authorNames[mb_strtolower($nm)] = $nm; }
    }
    $authorNames = array_values($authorNames);
    if (count($authorNames) > 10) { $errors[] = 'Tối đa 10 tác giả.'; }
    foreach ($authorNames as $nm) { if (mb_strlen($nm) > 150) { $errors[] = 'Tên tác giả tối đa 150 ký tự.'; break; } }

    [$coverErr, $coverExt] = validate_cover_upload('cover');
    if ($coverErr) { $errors[] = $coverErr; }
    $removeCover = !empty($_POST['remove_cover']);

    if (!$errors && $form['isbn'] !== '') {
        $st = $pdo->prepare('SELECT id FROM books WHERE isbn = ? AND id <> ?');
        $st->execute([$form['isbn'], $id]);
        if ($st->fetch()) { $errors[] = 'ISBN này đã được dùng cho sản phẩm khác.'; }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // nhà xuất bản: nhập mới hoặc chọn sẵn
            $pubId = null;
            if ($form['publisher_new'] !== '') {
                $st = $pdo->prepare('SELECT id FROM publishers WHERE name = ?');
                $st->execute([$form['publisher_new']]);
                $pubId = $st->fetchColumn();
                if (!$pubId) {
                    $pdo->prepare('INSERT INTO publishers (name) VALUES (?)')->execute([$form['publisher_new']]);
                    $pubId = (int)$pdo->lastInsertId();
                }
            } elseif ((int)$form['publisher_id'] > 0) {
                $st = $pdo->prepare('SELECT id FROM publishers WHERE id = ?');
                $st->execute([(int)$form['publisher_id']]);
                $pubId = $st->fetchColumn() ?: null;
            }

            $isbn  = $form['isbn'] === '' ? null : $form['isbn'];
            $sale  = $form['sale_price'] === '' ? null : (int)$form['sale_price'];
            $pages = $form['pages'] === '' ? null : (int)$form['pages'];
            $year  = $form['publish_year'] === '' ? null : (int)$form['publish_year'];
            $desc  = $form['description'] === '' ? null : $form['description'];
            $newStock = (int)$form['stock'];

            if ($book) {
                // khóa dòng để so sánh tồn kho chính xác (có thể vừa có đơn mới)
                $st = $pdo->prepare('SELECT stock FROM books WHERE id = ? FOR UPDATE');
                $st->execute([$id]);
                $curStock = (int)$st->fetchColumn();

                $pdo->prepare(
                    'UPDATE books SET title=?, isbn=?, description=?, price=?, sale_price=?, stock=?, pages=?, publish_year=?, publisher_id=?, status=? WHERE id=?'
                )->execute([$form['title'], $isbn, $desc, (int)$form['price'], $sale, $newStock, $pages, $year, $pubId, $form['status'], $id]);

                if ($newStock !== $curStock) {
                    $pdo->prepare("INSERT INTO stock_movements (book_id, change_qty, reason, note, created_by) VALUES (?, ?, 'adjust', ?, ?)")
                        ->execute([$id, $newStock - $curStock, 'Quản trị chỉnh tồn kho: ' . $curStock . ' → ' . $newStock, $admin['id']]);
                }
            } else {
                // slug duy nhất
                $base = slugify($form['title']);
                $slug = $base;
                $chk  = $pdo->prepare('SELECT 1 FROM books WHERE slug = ?');
                for ($i = 2; ; $i++) {
                    $chk->execute([$slug]);
                    if (!$chk->fetchColumn()) { break; }
                    $slug = $base . '-' . $i;
                }
                $pdo->prepare(
                    'INSERT INTO books (title, slug, isbn, description, price, sale_price, stock, pages, publish_year, publisher_id, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$form['title'], $slug, $isbn, $desc, (int)$form['price'], $sale, $newStock, $pages, $year, $pubId, $form['status']]);
                $id = (int)$pdo->lastInsertId();

                if ($newStock > 0) {
                    $pdo->prepare("INSERT INTO stock_movements (book_id, change_qty, reason, note, created_by) VALUES (?, ?, 'import', 'Nhập kho khi tạo sản phẩm', ?)")
                        ->execute([$id, $newStock, $admin['id']]);
                }
            }

            // tác giả
            $pdo->prepare('DELETE FROM book_authors WHERE book_id = ?')->execute([$id]);
            $selA = $pdo->prepare('SELECT id FROM authors WHERE name = ? LIMIT 1');
            $insA = $pdo->prepare('INSERT INTO authors (name) VALUES (?)');
            $link = $pdo->prepare('INSERT IGNORE INTO book_authors (book_id, author_id) VALUES (?, ?)');
            foreach ($authorNames as $nm) {
                $selA->execute([$nm]);
                $aid = $selA->fetchColumn();
                if (!$aid) { $insA->execute([$nm]); $aid = (int)$pdo->lastInsertId(); }
                $link->execute([$id, $aid]);
            }

            // danh mục
            $pdo->prepare('DELETE FROM book_categories WHERE book_id = ?')->execute([$id]);
            $linkC = $pdo->prepare('INSERT INTO book_categories (book_id, category_id) VALUES (?, ?)');
            foreach ($selCats as $cid) { $linkC->execute([$id, $cid]); }

            $pdo->commit();

            // ảnh bìa (xử lý sau khi lưu DB thành công; file không thể rollback)
            $coverWarn = '';
            $slugNow = $book ? (string)$book['slug'] : $slug;
            if ($coverExt !== null) {
                $fn = save_cover_upload($slugNow, $coverExt, 'cover');
                if ($fn) {
                    $pdo->prepare('UPDATE books SET cover_image = ? WHERE id = ?')->execute([$fn, $id]);
                } else {
                    $coverWarn = ' Nhưng chưa lưu được ảnh bìa (kiểm tra quyền ghi thư mục uploads/covers).';
                }
            } elseif ($removeCover && $book) {
                delete_cover_files($slugNow);
                $pdo->prepare('UPDATE books SET cover_image = NULL WHERE id = ?')->execute([$id]);
            }
            audit_log((int)$admin['id'], $book ? 'admin_book_update' : 'admin_book_create', 'books', $id, $form['title']);
            flash_set($coverWarn ? 'error' : 'success', ($book ? 'Đã cập nhật sản phẩm.' : 'Đã thêm sản phẩm mới.') . $coverWarn);
            redirect('books.php');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('book_form: ' . $ex->getMessage());
            $errors[] = ($ex->getCode() === '23000') ? 'Dữ liệu bị trùng (ISBN hoặc đường dẫn), vui lòng kiểm tra lại.' : 'Có lỗi hệ thống, vui lòng thử lại.';
        }
    }
}

admin_head($book ? 'Sửa sản phẩm' : 'Thêm sản phẩm', 'books');
?>
<div class="a-title">
    <div>
        <p><a href="books.php">← Danh sách sản phẩm</a></p>
        <h1><?= $book ? 'Sửa sản phẩm' : 'Thêm sản phẩm' ?></h1>
    </div>
</div>

<?php if ($errors): ?>
    <div class="a-alert error"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form class="a-form" method="post" enctype="multipart/form-data" action="book_form.php<?= $book ? '?id=' . $id : '' ?>" novalidate>
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">

    <div class="a-grid3">
        <div>
            <div class="a-card">
                <h3>Thông tin sách</h3>
                <label for="title">Tên sách *</label>
                <input type="text" id="title" name="title" maxlength="255" value="<?= e($form['title']) ?>">

                <label for="authors">Tác giả</label>
                <input type="text" id="authors" name="authors" placeholder="Nhiều tác giả cách nhau bằng dấu phẩy" value="<?= e($form['authors']) ?>">

                <div class="row2">
                    <div><label for="isbn">ISBN</label><input type="text" id="isbn" name="isbn" maxlength="20" value="<?= e($form['isbn']) ?>"></div>
                    <div><label for="publish_year">Năm xuất bản</label><input type="number" id="publish_year" name="publish_year" min="1901" max="2155" value="<?= e($form['publish_year']) ?>"></div>
                </div>
                <div class="row2">
                    <div><label for="publisher_id">Nhà xuất bản</label>
                        <select id="publisher_id" name="publisher_id">
                            <option value="0">— Chưa chọn —</option>
                            <?php foreach ($publishers as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= (string)$p['id'] === $form['publisher_id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div><label for="publisher_new">…hoặc nhập NXB mới</label><input type="text" id="publisher_new" name="publisher_new" maxlength="150" value="<?= e($form['publisher_new']) ?>"></div>
                </div>

                <label for="description">Mô tả</label>
                <textarea id="description" name="description" rows="7"><?= e($form['description']) ?></textarea>
            </div>
        </div>

        <div>
            <div class="a-card">
                <h3>Ảnh bìa</h3>
                <?php $curCover = $book ? cover_url($book, '../') : null; ?>
                <div class="a-coverbox"><?php if ($curCover): ?><img id="cover-preview" src="<?= e($curCover) ?>" alt="Ảnh bìa hiện tại"><?php else: ?><img id="cover-preview" alt="" hidden><span id="cover-empty">Chưa có ảnh bìa</span><?php endif; ?></div>
                <label for="cover"><?= $curCover ? 'Thay ảnh bìa' : 'Chọn ảnh bìa' ?></label>
                <input type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/webp">
                <p class="hint">JPG, PNG hoặc WEBP, tối đa 2 MB. Ảnh được lưu thành <code>uploads/covers/<?= e($book['slug'] ?? '&lt;slug&gt;') ?>.đuôi</code></p>
                <?php if ($curCover): ?><label class="a-inline"><input type="checkbox" name="remove_cover" value="1"> Xóa ảnh bìa hiện tại</label><?php endif; ?>
            </div>

            <div class="a-card">
                <h3>Giá và kho</h3>
                <label for="price">Giá bán (đ) *</label>
                <input type="text" id="price" name="price" inputmode="numeric" value="<?= e($form['price']) ?>">
                <label for="sale_price">Giá khuyến mãi (đ)</label>
                <input type="text" id="sale_price" name="sale_price" inputmode="numeric" placeholder="Để trống nếu không giảm" value="<?= e($form['sale_price']) ?>">
                <div class="row2">
                    <div><label for="stock">Tồn kho *</label><input type="number" id="stock" name="stock" min="0" value="<?= e($form['stock']) ?>"></div>
                    <div><label for="pages">Số trang</label><input type="number" id="pages" name="pages" min="1" max="65535" value="<?= e($form['pages']) ?>"></div>
                </div>
                <?php if ($book): ?><p class="hint">Đổi tồn kho sẽ được ghi vào lịch sử kho (điều chỉnh).</p><?php endif; ?>
                <label for="status">Trạng thái</label>
                <select id="status" name="status">
                    <option value="active" <?= $form['status'] === 'active' ? 'selected' : '' ?>>Đang bán</option>
                    <option value="hidden" <?= $form['status'] === 'hidden' ? 'selected' : '' ?>>Ẩn khỏi cửa hàng</option>
                </select>
            </div>

            <div class="a-card">
                <h3>Danh mục</h3>
                <div class="checks" style="flex-direction:column;gap:6px">
                    <?php foreach ($allCats as $c): ?>
                        <label style="<?= $c['parent_id'] ? 'padding-left:18px' : '' ?>">
                            <input type="checkbox" name="cats[]" value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], $selCats, true) ? 'checked' : '' ?>>
                            <?= e($c['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="actions">
        <button type="submit" class="a-btn red">Lưu sản phẩm</button>
        <a class="a-btn line" href="books.php">Hủy</a>
    </div>
</form>
<script>
document.getElementById('cover').addEventListener('change', function () {
    var f = this.files[0], img = document.getElementById('cover-preview'), em = document.getElementById('cover-empty');
    if (!f) { return; }
    img.src = URL.createObjectURL(f); img.hidden = false; if (em) { em.hidden = true; }
});
</script>
<?php admin_foot(); ?>
