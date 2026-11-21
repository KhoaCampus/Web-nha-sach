<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../auth_lib.php';
require_once __DIR__ . '/_login_shell.php';

// token nằm trên URL: không cho trình duyệt/trang khác lưu hoặc gửi kèm referrer
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$valid = find_reset_token($token, 'admin') !== null;

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $error = 'Phiên làm việc không hợp lệ, vui lòng thử lại.';
    } else {
        $err = complete_password_reset($token, 'admin', $_POST['password'] ?? '', $_POST['password2'] ?? '');
        if ($err === null) {
            flash_set('success', 'Đặt lại mật khẩu thành công! Hãy đăng nhập bằng mật khẩu mới.');
            redirect('login.php');
        }
        $error = $err;
        $valid = find_reset_token($token, 'admin') !== null;
    }
}

al_open('Đặt lại mật khẩu');
?>
    <form class="al-form" method="post" action="reset_password.php" novalidate>
        <h2>Đặt lại mật khẩu</h2>

        <?php if (!$valid): ?>
            <div class="a-alert error">Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.</div>
            <a class="a-btn red block" href="forgot_password.php">Yêu cầu liên kết mới</a>
        <?php else: ?>
            <p class="muted">Nhập mật khẩu mới cho tài khoản quản trị.</p>
            <?php if ($error): ?><div class="a-alert error"><?= e($error) ?></div><?php endif; ?>
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <label for="password">Mật khẩu mới (tối thiểu 8 ký tự)</label>
            <input type="password" id="password" name="password" autocomplete="new-password" autofocus>
            <label for="password2">Nhập lại mật khẩu mới</label>
            <input type="password" id="password2" name="password2" autocomplete="new-password">
            <button type="submit" class="a-btn red block">Cập nhật mật khẩu</button>
        <?php endif; ?>

        <p class="al-back"><a href="login.php">← Quay lại đăng nhập</a></p>
    </form>
<?php al_close(); ?>
