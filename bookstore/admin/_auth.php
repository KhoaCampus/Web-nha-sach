<?php
// Khởi tạo chung cho mọi trang quản trị: nạp thư viện, kiểm tra quyền admin, dựng layout.
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../checkout_lib.php';
require_once __DIR__ . '/../order_lib.php';
require_once __DIR__ . '/_charts.php';

/** Chỉ cho admin vào. Chưa đăng nhập -> trang đăng nhập admin; đăng nhập rồi nhưng không phải admin -> 403. */
function require_admin(): array
{
    $u = current_user();   // current_user() đã kiểm tra lại role/status trong DB
    if (!$u) {
        redirect('login.php');
    }
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><title>403</title>'
           . '<link rel="stylesheet" href="admin.css"></head><body class="a-403"><div><h1>403</h1>'
           . '<p>Bạn không có quyền truy cập trang quản trị.</p>'
           . '<a class="a-btn red" href="../index.php">Về trang bán hàng</a> '
           . '<a class="a-btn line" href="logout.php">Đăng xuất</a></div></body></html>';
        exit;
    }
    return $u;
}

/** "Nguyễn Nhật Ánh" -> "nguyen-nhat-anh" */
function slugify(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $map = [
        'a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'e' => 'èéẹẻẽêềếệểễ', 'i' => 'ìíịỉĩ',
        'o' => 'òóọỏõôồốộổỗơờớợởỡ', 'u' => 'ùúụủũưừứựửữ', 'y' => 'ỳýỵỷỹ', 'd' => 'đ',
    ];
    $pairs = [];
    foreach ($map as $to => $chars) {
        foreach (mb_str_split($chars) as $c) { $pairs[$c] = $to; }
    }
    $s = strtr($s, $pairs);
    $s = preg_replace('/\p{M}+/u', '', $s);          // bỏ dấu tổ hợp (chuỗi NFD)
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    return $s !== '' ? mb_substr($s, 0, 200) : 'sach';
}

/** Đường dẫn trang hiện tại, giữ nguyên query và ghi đè một vài tham số */
function admin_url(array $over = []): string
{
    $p = $_GET;
    foreach ($over as $k => $v) {
        if ($v === null) { unset($p[$k]); } else { $p[$k] = $v; }
    }
    return basename($_SERVER['SCRIPT_NAME']) . ($p ? '?' . http_build_query($p) : '');
}

function pager_html(int $page, int $pages): string
{
    if ($pages <= 1) {
        return '';
    }
    $h = '<div class="a-pager">';
    if ($page > 1) { $h .= '<a href="' . e(admin_url(['page' => $page - 1])) . '">‹</a>'; }
    $from = max(1, $page - 3);
    $to   = min($pages, $page + 3);
    if ($from > 1) { $h .= '<a href="' . e(admin_url(['page' => 1])) . '">1</a>' . ($from > 2 ? '<span>…</span>' : ''); }
    for ($i = $from; $i <= $to; $i++) {
        $h .= '<a href="' . e(admin_url(['page' => $i])) . '"' . ($i === $page ? ' class="cur"' : '') . '>' . $i . '</a>';
    }
    if ($to < $pages) { $h .= ($to < $pages - 1 ? '<span>…</span>' : '') . '<a href="' . e(admin_url(['page' => $pages])) . '">' . $pages . '</a>'; }
    if ($page < $pages) { $h .= '<a href="' . e(admin_url(['page' => $page + 1])) . '">›</a>'; }
    return $h . '</div>';
}

function status_badge(string $status): string
{
    $label = $GLOBALS['ORDER_STATUS'][$status] ?? $status;
    return '<span class="a-badge st-' . e($status) . '">' . e($label) . '</span>';
}

function pct_change(float $cur, float $prev): ?string
{
    if ($prev <= 0) {
        return $cur > 0 ? '+100%' : null;
    }
    $p = round(($cur - $prev) / $prev * 100);
    return ($p >= 0 ? '+' : '') . (int)$p . '%';
}

/** Mở trang: <head>, sidebar, thanh trên cùng, thẻ <main>. $search = ['action' => 'books.php', 'placeholder' => '...'] (tùy chọn) */
function admin_head(string $title, string $active, ?array $search = null): void
{
    $user    = current_user();
    $flash   = flash_get();
    $pending = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();

    $nav = [
        'dashboard' => ['index.php',   '▦', 'Tổng quan'],
        'books'     => ['books.php',   '📘', 'Sản phẩm'],
        'orders'    => ['orders.php',  '🧾', 'Đơn hàng'],
        'users'     => ['users.php',   '👥', 'Khách hàng'],
        'categories' => ['categories.php', '🗂', 'Danh mục'],
        'coupons'   => ['coupons.php', '🏷', 'Khuyến mãi'],
        'reports'   => ['reports.php', '📈', 'Báo cáo'],
    ];
    ?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - Quản trị MPB</title>
    <link rel="stylesheet" href="admin.css">
</head>
<body class="a-body">
<aside class="a-side">
    <a class="a-brand" href="index.php"><span class="a-logo">M</span><span>MPB<small>Bookstore</small></span></a>
    <nav class="a-nav">
        <?php foreach ($nav as $key => [$href, $ico, $label]): ?>
            <a href="<?= e($href) ?>" class="<?= $active === $key ? 'on' : '' ?>">
                <span class="ico"><?= $ico ?></span><?= e($label) ?>
                <?php if ($key === 'orders' && $pending > 0): ?><span class="a-count"><?= $pending ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="a-support">
        <strong>Trang bán hàng</strong>
        <span>Xem cửa hàng như khách hàng.</span>
        <a class="a-btn red sm" href="../index.php" target="_blank" rel="noopener">Mở cửa hàng</a>
    </div>
</aside>

<div class="a-wrap">
    <header class="a-top">
        <?php if ($search): ?>
            <form class="a-search" method="get" action="<?= e($search['action']) ?>">
                <input type="text" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="<?= e($search['placeholder']) ?>">
                <button type="submit" aria-label="Tìm">🔍</button>
            </form>
        <?php else: ?><div></div><?php endif; ?>
        <div class="a-user">
            <span class="a-avatar"><?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?></span>
            <div><strong><?= e($user['full_name']) ?></strong><small>Quản trị viên</small></div>
            <a class="a-logout" href="logout.php" title="Đăng xuất">⎋</a>
        </div>
    </header>
    <main class="a-main">
        <?php if ($flash): ?><div class="a-alert <?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
<?php
}

function admin_foot(): void
{
    echo "    </main>\n</div>\n</body>\n</html>\n";
}
