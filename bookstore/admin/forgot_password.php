<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../auth_lib.php';
require_once __DIR__ . '/_login_shell.php';

$u = current_user();
if ($u && $u['role'] === 'admin') {
    redirect('index.php');
}

$error   = '';
$sent    = false;
$devLink = null;
$email   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim(mb_strtolower($_POST['email'] ?? ''));
    if (!csrf_valid()) {
        $error = 'Phiên làm việc không hợp lệ, vui lòng thử lại.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        $error = 'Vui lòng nhập một địa chỉ email hợp lệ.';
    } else {
        // chỉ tài khoản quản trị mới nhận được token; thời hạn ngắn hơn (15 phút)
        $token = issue_reset_token($email, 'admin', 15);
        $sent  = true;   // luôn báo giống nhau dù email có tồn tại hay không
        if ($token !== null) {
            if (RESET_DEV_LINK && is_local_request()) {
                $devLink = 'reset_password.php?token=' . $token;
            } else {
                send_reset_mail($email, site_base_url() . '/reset_password.php?token=' . $token);
            }
        }
    }
}

al_open('Quên mật khẩu');
?>
    <form class="al-form" method="post" action="forgot_password.php" novalidate>
        <h2>Quên mật khẩu</h2>
        <p class="muted">Nhập email quản trị, hệ thống sẽ gửi liên kết đặt lại mật khẩu (hiệu lực 15 phút).</p>

        <?php if ($error): ?><div class="a-alert error"><?= e($error) ?></div><?php endif; ?>

        <?php if ($sent): ?>
            <div class="a-alert success">Nếu email này thuộc một tài khoản quản trị, hướng dẫn đặt lại mật khẩu đã được gửi đi.</div>
            <?php if ($devLink): ?>
                <div class="dev-box">
                    <strong>Chế độ localhost</strong>
                    <p>XAMPP chưa cấu hình gửi email nên liên kết hiển thị tại đây (chỉ thấy khi truy cập từ chính máy này):</p>
                    <a href="<?= e($devLink) ?>">Đặt lại mật khẩu →</a>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <?= csrf_field() ?>
            <label for="email">Email</label>
            <input type="email" id="email" name="email" placeholder="admin@example.com" autofocus required value="<?= e($email) ?>">
            <button type="submit" class="a-btn red block">Gửi liên kết đặt lại</button>
        <?php endif; ?>

        <p class="al-back"><a href="login.php">← Quay lại đăng nhập</a></p>
    </form>
<?php al_close(); ?>
