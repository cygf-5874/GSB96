# dfamin —— PHP 8 的 DFA 最小化库

把「状态等价类合并」「死状态显式化」「两个 DFA 是否同语言」三件事同时做对的极简库：

- 语言/框架：PHP 8，**仅标准库**（php-cli，无 composer，无第三方依赖，不用 mbstring）。
- 自检入口：`bash scripts/check.sh`（内部就是 `php check/check.php`）。
  `check/` 是**固定验收程序**，不要改。
- 四个类在 `src/` 下：`Dfa`（数据 + 归一化输出）、`Minimizer`（最小化 / 补全 / 等价判定）、
  `InputError`（非法入参）、`Meter`（规模轴的计数器，**固定入口，字段名别改**）。

## 快速开始

```bash
bash scripts/check.sh                # 跑全部 12 个场景，全过才 exit 0
bash scripts/check.sh -list          # 列出全部场景
bash scripts/check.sh --only merge   # 只跑一组
php -l src/Dfa.php                   # 语法检查
```

## 公开 API

```php
$d = new Dfa(array $states, array $alphabet, array $transitions, array $accepting, int $start);

$d->getStates(): int[]                                    // 升序
$d->getAlphabet(): string[]                               // 保持构造时的顺序
$d->getTransitions(): array<int, array<string, int>>      // 按 state 升序、字符按字符表顺序
$d->getAccepting(): int[]                                 // 升序
$d->getStart(): int
$d->transition(int $state, string $char): ?int            // 未显式给出 -> null（隐式死状态）
$d->accepts(string $input): bool                          // 缺失转移落到隐式死状态
$d->toArray(): array                                      // 归一化输出（契约 11）

Minimizer::minimize(Dfa $d, bool $pruneDead = true): Dfa    // 纯函数：不修改 $d
Minimizer::complete(Dfa $d): Dfa                            // 把隐式死状态显式化
Minimizer::equivalent(Dfa $a, Dfa $b): bool                 // 语言是否相同

Meter::$refinements / Meter::$pairVisits / Meter::reset()   // 规模轴计数器（契约 10）
```

## 对外契约（12 条）

1. `Dfa` 由 `states`（编号 `0..n-1`，顺序随意但必须无重号、无缺号）、`alphabet`（**有序**字符数组，
   元素是**单个字节**，不得重复）、`transitions`（`state => char => state`；**缺失即隐式死状态**）、
   `accepting`（`states` 的子集）、`start` 组成。`transitions` 里出现的状态键、字符与目标状态号
   都必须合法（字符必须属于 `alphabet`）。任一非法 → `InputError`。
2. `Minimizer::minimize(Dfa $d, bool $pruneDead = true): Dfa` 返回新 DFA，不修改 `$d`。
3. 先移除**不可达**状态，再按 **Myhill–Nerode 等价类**合并；**接受状态与不接受状态永不同类**。
4. `$pruneDead = true` 时，所有「不导向任何接受状态」的非接受状态必须合并成**一个**并在结果里移除
   （若因此没有留下任何等价类，结果是「只有起点、无转移、不接受」的 DFA）；`false` 时保留该类。
5. 结果状态编号必须**确定**：含起点的等价类编号恒为 `0`，其余等价类按各自**最小原编号**升序依次编号。
6. 语言等价：`minimize` 前后对**所有长度 ≤ 7 的串**接受性必须完全一致（固定件在子进程里枚举）。
7. 最小性：结果状态数必须等于固定件自带的**朴素 O(n²) 划分参照实现**给出的等价类个数。
8. 幂等：`minimize(minimize($d))` 与 `minimize($d)` 的 `toArray()` 逐字段相同。
9. **补全**：`Minimizer::complete(Dfa $d): Dfa` 把隐式死状态**显式化**为一个新状态（编号取
   `$d` 的当前最大编号 `+1`），补全所有缺失转移，并让它对所有字符自环（它不接受任何串）。
   只要 `$d` 存在至少一处缺失转移就新增这个状态；转移本来就完整时**不新增状态**（返回归一化副本）。
   因此 `complete` 之后 `minimize` 会先把显式死状态合并、再按 `$pruneDead` 处理，所以
   `minimize(complete($d))` 与 `minimize($d)` 必须**语言等价**（状态数可以不同）。
10. **等价判定**：`Minimizer::equivalent(Dfa $a, Dfa $b): bool` 判定两个 DFA 的语言是否相同；
    `$a` 与 `$b` 的 `alphabet` 必须逐元素同序，否则 `InputError`。要求与第 7 条咬合：
    `equivalent($a, $b)` 为 `true` **当且仅当** `minimize($a)` 与 `minimize($b)` 的 `toArray()`
    在「状态编号重排后」同构。
11. **规模轴**：20 000 状态、字母表 4 的 DFA 上，`minimize` 必须满足
    `Meter::$refinements < 64` 且 `Meter::$pairVisits < 20 × states`（禁止 O(n²) 填表法；
    `Meter::$refinements` 还必须 `>= 1`，即计数器要真实反映划分变化）。固定件把这条放在独立
    子进程里跑，用 **60 秒看门狗**兜底（按本机标定）：构造 `Dfa` 本身也要在这条预算内完成。
    - `Meter::$refinements`：划分发生变化的次数。初始划分（按接受性分成两类）不计；
      此后每让等价类集合多出至少一个新类，就 `+1`。
    - `Meter::$pairVisits`：状态对等价性考察次数。每把两个状态号放在一起比较
      （填表法的内层循环、两两对比等）就 `+1`。
12. `Dfa::toArray()` 的输出结构固定：`states` 升序、`alphabet` 按给定顺序、
    `transitions` 按 state 升序 + 字符表顺序（结果里的每个状态都出现在表中，无出边则为空表）、
    `accepting` 升序；**不得依赖 PHP 数组的 hash 顺序**。边界：单状态、无接受状态、全部接受、
    空字母表、起点不存在、越界状态号 → `InputError`；三个入口都是纯函数，不修改入参。

## 补充说明（把判据说死）

- **隐式死状态**：读入某个字符时 `(当前状态, 字符)` 没有显式转移，就进入一个「永远拒绝」的虚拟汇点。
  它照样算「可达」，也照样不导向任何接受状态，所以在 `pruneDead = true` 时会被剪掉。
- **编号口径**：先把可达状态按 Myhill–Nerode 划分成等价类，把含起点的类排到 0，
  其余类按「类内最小原状态号」升序排成 1、2、……；结果 `start` 恒为 `0`、`states` 恒为 `0..k-1`。
- **`complete` 与 `pruneDead` 方向相反**：`complete` 把死状态**显式化**（多一个状态、转移变完整），
  `pruneDead = true` 把死状态**移除**（少一个状态、转移可以残缺）。两者作用于同一个「死状态」，
  必须分清「语言」与「表示」两个层次。
- 非法入参一律以 `InputError` 结束，不许把原生 `TypeError` / `Warning` 泄漏出来。

## 目录

```
README.md          本文件
PROMPT.md          出题用的 User Prompt
autoload.php       极简 spl_autoload_register（__DIR__ 相对定位 src/，并显式加载 src/meter.php）
src/Dfa.php        自动机数据结构
src/Minimizer.php  最小化 / 补全 / 等价判定
src/InputError.php 非法入参异常
src/meter.php      规模轴计数器（固定入口，别改字段名）
check/check.php    固定验收程序（别改）
scripts/check.sh   固定验收的 shell 入口
```
