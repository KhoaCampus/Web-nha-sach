<?php
// Dùng lại logout của trang bán hàng. Vì URL hiện tại nằm trong /admin/ nên
// lệnh redirect('login.php') bên trong sẽ dẫn về admin/login.php.
require __DIR__ . '/../logout.php';
