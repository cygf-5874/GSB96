<?php

declare(strict_types=1);

/**
 * 极简自动加载：把 src/ 下的类按类名映射成同名的 .php 文件。
 *
 *   Dfa       -> src/Dfa.php
 *   Minimizer -> src/Minimizer.php
 *   InputError-> src/InputError.php
 *
 * src/meter.php 的文件名是小写的（由固定件约定，不要改名），
 * 在大小写敏感的文件系统上按类名推不出这个文件名，所以这里显式加载一次。
 *
 * 只认全局命名空间里的标识符类名；用 __DIR__ 定位，不写死绝对路径。
 * 零依赖，不引入 composer。
 */

spl_autoload_register(static function (string $class): void {
    // 只要简单标识符；带命名空间分隔符或非法字符的一律不处理。
    if ($class === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class) !== 1) {
        return;
    }

    $file = __DIR__ . '/src/' . $class . '.php';

    if (is_file($file)) {
        require $file;
    }
});

// 小写文件名的固定入口，显式加载。
$__meter = __DIR__ . '/src/meter.php';
if (is_file($__meter)) {
    require_once $__meter;
}
unset($__meter);
