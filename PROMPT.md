先判等价，再求最小，两步都不能少。dfamin 是 PHP 8 的 DFA 最小化库
（php-cli，无 composer，无第三方依赖），自检走 `scripts/check.sh`
（`check/` 是固定验收程序，别改）。

`src/` 下三个类是空壳，只留了签名与方法体里的抛错；`src/meter.php` 是固定件要用的计数器，别改它的字段名。

任务：按 README「对外契约」的 12 条把最小化、补全与等价判定实现出来，让固定件全过。

验收：
- php check/check.php 退出码 0，12 个场景全过
  （build 2 + merge 3 + numbering 2 + equivalence 2 + complete 2 + scale 1）。

约束：
1. 不改 `check/`、不改 `src/meter.php`；已公开的类与方法签名不变（可新增）。
2. 不引入第三方依赖，不用 composer，不用 mbstring，只用 PHP 标准库。
3. 结果不得依赖 PHP 数组的 hash 顺序或 `spl_object_id` 之类不可复现的值。
4. 参数非法一律抛 `InputError`，不许抛原生 `TypeError` / `Warning`。
