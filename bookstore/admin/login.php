<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../auth_lib.php';
require_once __DIR__ . '/_login_shell.php';

$u = current_user();
if ($u && $u['role'] === 'admin') {
    redirect('index.php');
}

$flash = flash_get();
$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim(mb_strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (!csrf_valid()) {
        $error = 'Phiên làm việc không hợp lệ, vui lòng thử lại.';
    } elseif ($email === '' || $password === '') {
        $error = 'Vui lòng nhập email và mật khẩu.';
    } else {
        $error = try_login($email, $password, true) ?? '';
        if ($error === '') {
            redirect('index.php');
        }
    }
}

al_open('Đăng nhập quản trị');
?>
    <form class="al-form" method="post" action="login.php" novalidate>
        <h2>Đăng nhập</h2>
        <p class="muted">Chào mừng bạn quay lại. Vui lòng nhập thông tin.</p>

        <?php if ($flash): ?><div class="a-alert <?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="a-alert error"><?= e($error) ?></div><?php endif; ?>

        <?= csrf_field() ?>
        <label for="email">Email</label>
        <input type="email" id="email" name="email" placeholder="admin@example.com" autofocus required value="<?= e($email) ?>">

        <label for="password">Mật khẩu</label>
        <input type="password" id="password" name="password" placeholder="••••••••" required>
        <p class="al-link"><a href="forgot_password.php">Quên mật khẩu?</a></p>

        <button type="submit" class="a-btn red block">Đăng nhập hệ thống</button>
        <p class="al-back"><a href="../index.php">← Về trang bán hàng</a></p>
    </form>
<?php al_close(); ?>
