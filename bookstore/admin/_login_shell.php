<?php
// Khung chung cho các trang đăng nhập / quên mật khẩu của quản trị (panel tối bên trái + form bên phải)

function al_open(string $title): void
{
    ?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - Quản trị MPB</title>
    <link rel="stylesheet" href="admin.css">
</head>
<body class="al-body">
<section class="al-left">
    <div class="al-brand"><span class="a-logo">M</span><span>MPB<small>Bookstore</small></span></div>
    <div class="al-hero">
        <h1>Hệ thống Quản trị Bookstore</h1>
        <p>Quản lý sản phẩm, đơn hàng, khách hàng và khuyến mãi của nhà sách trong một nơi duy nhất.</p>
        <div class="al-mini">
            <small>Doanh thu hôm nay</small>
            <div class="al-bars"><i style="height:35%"></i><i style="height:48%"></i><i style="height:42%"></i><i style="height:60%"></i><i style="height:52%"></i><i class="hot" style="height:90%"></i></div>
        </div>
    </div>
    <small class="al-copy">© <?= date('Y') ?> MPB Bookstore. Chỉ dành cho nhân viên được cấp quyền.</small>
</section>
<section class="al-right">
<?php
}

function al_close(): void
{
    echo "</section>\n</body>\n</html>\n";
}
