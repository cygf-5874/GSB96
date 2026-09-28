<?php

declare(strict_types=1);

/**
 * GSB96 · dfamin 固定验收（**别改这个文件**）。
 *
 * 用法：
 *   php check/check.php                    跑全部 12 个场景，全过才退 0
 *   php check/check.php -list              列出全部场景
 *   php check/check.php --only merge       只跑一组（也支持 --only a,b）
 *
 * 六组共 12 个场景：
 *   build       2 —— 合法构造 + toArray 归一化（状态号乱序 / alphabet 去重校验）；越界状态号报 InputError
 *   merge       3 —— 接受态与非接受态不合并；不可达先移除；死状态并成一个（含边界退化）
 *   numbering   2 —— 按最小原编号重排；幂等
 *   equivalence 2 —— 长度 <= 7 的串上接受性一致（子进程枚举）；状态数与朴素参照相等
 *   complete    2 —— 补全后死状态显式化；minimize(complete($d)) 与 minimize($d) 语言等价
 *   scale       1 —— 20 000 状态、字母表 4 下的 refinements / pairVisits 上界（独立子进程 + 看门狗）
 *
 * 只依赖 PHP 8 标准库；判据完全确定，不读时间（只有规模场景的看门狗用墙钟兜底）、
 * 不用随机源、不依赖数组 hash 顺序。
 * 失败不早退，每个场景都用 try/catch 兜住 Throwable。
 */

require __DIR__ . '/../autoload.php';

const SCALE_STATES = 20000;
const SCALE_LEVELS = 8;
const SCALE_ALPHABET = ['a', 'b', 'c', 'd'];
const SCALE_TIMEOUT_SECONDS = 60.0;

// ---------------------------------------------------------------------------
// 断言失败
// ---------------------------------------------------------------------------

final class CheckFailure extends \Exception
{
    public string $expected;

    public string $actual;

    public function __construct(string $message, string $expected = '-', string $actual = '-')
    {
        parent::__construct($message);
        $this->expected = $expected;
        $this->actual = $actual;
    }
}

// ---------------------------------------------------------------------------
// 通用工具
// ---------------------------------------------------------------------------

function show($v): string
{
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if (is_string($v)) {
        return "'" . $v . "'";
    }
    if ($v === null) {
        return 'null';
    }
    if (is_array($v)) {
        $enc = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $enc === false ? '(array)' : $enc;
    }

    return (string) $v;
}

function expect_same($want, $got, string $what): void
{
    if ($want !== $got) {
        throw new CheckFailure($what, show($want), show($got));
    }
}

function expect_true(bool $cond, string $what, string $expected = 'true'): void
{
    if (!$cond) {
        throw new CheckFailure($what, $expected, 'false');
    }
}

/** 断言 $s 构造 Dfa 时抛 InputError。 */
function expect_input_error(array $s, string $what): void
{
    try {
        dfa_of($s);
    } catch (InputError $exc) {
        return;
    } catch (\Throwable $exc) {
        throw new CheckFailure(
            $what . ' 应抛 InputError',
            'InputError',
            get_class($exc) . ': ' . $exc->getMessage()
        );
    }

    throw new CheckFailure($what . ' 应抛 InputError', 'InputError', '没有抛异常');
}

/** 断言 $fn 抛 InputError（用于 equivalent 这类不构造 Dfa 的入口）。 */
function expect_input_error_from(callable $fn, string $what): void
{
    try {
        $fn();
    } catch (InputError $exc) {
        return;
    } catch (\Throwable $exc) {
        throw new CheckFailure(
            $what . ' 应抛 InputError',
            'InputError',
            get_class($exc) . ': ' . $exc->getMessage()
        );
    }

    throw new CheckFailure($what . ' 应抛 InputError', 'InputError', '没有抛异常');
}

// ---------------------------------------------------------------------------
// DFA 规格（纯数组）与桥接
// ---------------------------------------------------------------------------

/**
 * @param int[]                          $states
 * @param string[]                       $alphabet
 * @param array<int, array<string, int>> $transitions
 * @param int[]                          $accepting
 *
 * @return array{states:int[],alphabet:string[],transitions:array<int,array<string,int>>,accepting:int[],start:int}
 */
function spec(array $states, array $alphabet, array $transitions, array $accepting, int $start): array
{
    return [
        'states' => $states,
        'alphabet' => $alphabet,
        'transitions' => $transitions,
        'accepting' => $accepting,
        'start' => $start,
    ];
}

function dfa_of(array $s): Dfa
{
    return new Dfa($s['states'], $s['alphabet'], $s['transitions'], $s['accepting'], $s['start']);
}

/**
 * 与 Dfa::accepts() 无关的独立接受性模拟器：直接走规格里的转移表，
 * 缺失的 (state, char) 即隐式死状态。等价性判据只信这一份实现。
 */
function sim_accepts(array $s, string $w): bool
{
    $accepting = [];
    foreach ($s['accepting'] as $q) {
        $accepting[$q] = true;
    }

    $q = $s['start'];
    $dead = false;
    $len = strlen($w);
    for ($i = 0; $i < $len; $i++) {
        if ($dead) {
            break;
        }
        $c = substr($w, $i, 1);
        if (isset($s['transitions'][$q][$c])) {
            $q = $s['transitions'][$q][$c];
        } else {
            $dead = true;
        }
    }

    return !$dead && isset($accepting[$q]);
}

/** 枚举字符表上所有长度 <= $maxLen 的串中被接受的那些（先长度、后字典序，顺序确定）。 */
function enum_accepted(array $s, int $maxLen): array
{
    $out = [];
    $cur = [''];
    for ($len = 0; $len <= $maxLen; $len++) {
        $next = [];
        foreach ($cur as $w) {
            if (sim_accepts($s, $w)) {
                $out[] = $w;
            }
            foreach ($s['alphabet'] as $c) {
                $next[] = $w . $c;
            }
        }
        $cur = $next;
    }

    return $out;
}

/** 长度 <= $maxLen 的串里第一处接受性差异；没有差异返回 null。 */
function first_diff(array $sa, array $sb, int $maxLen): ?string
{
    $cur = [''];
    for ($len = 0; $len <= $maxLen; $len++) {
        $next = [];
        foreach ($cur as $w) {
            if (sim_accepts($sa, $w) !== sim_accepts($sb, $w)) {
                return $w;
            }
            foreach ($sa['alphabet'] as $c) {
                $next[] = $w . $c;
            }
        }
        $cur = $next;
    }

    return null;
}

function compare_pair(array $pair, int $maxLen): array
{
    $witness = first_diff($pair['a'], $pair['b'], $maxLen);

    return [
        'name' => $pair['name'],
        'equal' => $witness === null,
        'aCount' => count(enum_accepted($pair['a'], $maxLen)),
        'bCount' => count(enum_accepted($pair['b'], $maxLen)),
        'witness' => $witness,
    ];
}

/**
 * 朴素 O(n²) 划分参照实现（契约 7）。返回与 Minimizer::minimize($s, $pruneDead)
 * 应当一致的结果状态数：先把隐式死状态显式化，再做可达裁剪 + Moore 划分。
 */
function ref_state_count(array $s, bool $pruneDead): int
{
    $n = count($s['states']);
    $alphabet = $s['alphabet'];
    $sink = $n; // 虚拟隐式死状态

    $accepting = [];
    foreach ($s['accepting'] as $q) {
        $accepting[$q] = true;
    }

    $delta = [];
    for ($q = 0; $q < $n; $q++) {
        foreach ($alphabet as $c) {
            $delta[$q][$c] = $s['transitions'][$q][$c] ?? $sink;
        }
    }
    foreach ($alphabet as $c) {
        $delta[$sink][$c] = $sink;
    }

    // 可达
    $reach = [$s['start'] => true];
    $stack = [$s['start']];
    while ($stack !== []) {
        $q = array_pop($stack);
        foreach ($alphabet as $c) {
            $t = $delta[$q][$c];
            if (!isset($reach[$t])) {
                $reach[$t] = true;
                $stack[] = $t;
            }
        }
    }
    $R = array_keys($reach);
    sort($R);

    // 共可达（能走到某个接受状态）—— 反向 BFS
    $pred = [];
    foreach ($R as $q) {
        foreach ($alphabet as $c) {
            $pred[$delta[$q][$c]][] = $q;
        }
    }
    $live = [];
    $stack = [];
    foreach ($R as $q) {
        if (isset($accepting[$q])) {
            $live[$q] = true;
            $stack[] = $q;
        }
    }
    while ($stack !== []) {
        $q = array_pop($stack);
        foreach ($pred[$q] ?? [] as $p) {
            if (!isset($live[$p])) {
                $live[$p] = true;
                $stack[] = $p;
            }
        }
    }

    // Moore 划分（朴素：反复按「接受性 + 各字符目标类」细分）
    $class = [];
    foreach ($R as $q) {
        $class[$q] = isset($accepting[$q]) ? 1 : 0;
    }
    $count = count(array_unique($class));
    while (true) {
        $sig = [];
        $next = [];
        foreach ($R as $q) {
            $parts = [$class[$q]];
            foreach ($alphabet as $c) {
                $parts[] = $class[$delta[$q][$c]];
            }
            $key = implode(',', $parts);
            if (!isset($sig[$key])) {
                $sig[$key] = count($sig) + 1;
            }
            $next[$q] = $sig[$key];
        }
        $newCount = count($sig);
        if ($newCount === $count) {
            break;
        }
        $class = $next;
        $count = $newCount;
    }

    if (!$pruneDead) {
        return $count;
    }

    // pruneDead：只数「含有共可达状态」的等价类；一个都没有 -> 退化成单起点，仍是 1 个状态
    $liveClasses = 0;
    $seen = [];
    foreach ($R as $q) {
        if (!isset($live[$q])) {
            continue;
        }
        $cid = $class[$q];
        if (!isset($seen[$cid])) {
            $seen[$cid] = true;
            $liveClasses++;
        }
    }

    return $liveClasses === 0 ? 1 : $liveClasses;
}

/**
 * 最小化结果的同构指纹：从 start 出发按 alphabet 顺序做 BFS 重编号，
 * 再逐状态列出「接受性 + 各字符的目标编号（缺失记 -1）」。
 * 两台同字母表的**最小化** DFA 指纹相同 <=> 互相同构 <=> 语言相同（契约 9 的「重排后同构」）。
 *
 * 只在固定件内部使用，不调用被测实现。
 */
function canon(array $s): string
{
    $accepting = [];
    foreach ($s['accepting'] as $q) {
        $accepting[$q] = true;
    }

    $start = $s['start'];
    if (!in_array($start, $s['states'], true)) {
        return '(bad-start)';
    }

    $idx = [$start => 0];
    $order = [$start];
    for ($i = 0; $i < count($order); $i++) {
        $q = $order[$i];
        foreach ($s['alphabet'] as $c) {
            $t = $s['transitions'][$q][$c] ?? null;
            if ($t === null || isset($idx[$t])) {
                continue;
            }
            $idx[$t] = count($order);
            $order[] = $t;
        }
    }

    $lines = [];
    foreach ($order as $q) {
        $cells = [];
        foreach ($s['alphabet'] as $c) {
            $t = $s['transitions'][$q][$c] ?? null;
            $cells[] = $t === null ? '-1' : (string) $idx[$t];
        }
        $lines[] = (isset($accepting[$q]) ? '1' : '0') . ':' . implode(',', $cells);
    }

    return count($order) . '|' . implode('|', $lines);
}

// ---------------------------------------------------------------------------
// 子进程：长度 <= 7 全枚举（契约 5）
//
// 子进程只拿规格数组，用上面的独立模拟器算接受性，绝不调用被测实现。
// 子进程起不来（proc_open 被禁 / 路径不可用）时回退到进程内同一份判据，结论完全一致。
// ---------------------------------------------------------------------------

function run_child_enum(string $jobFile): bool
{
    if (!function_exists('proc_open') || PHP_BINARY === '') {
        return false;
    }

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open([PHP_BINARY, __FILE__, '--_equivalence', $jobFile], $descriptors, $pipes, __DIR__);
    if (!is_resource($proc)) {
        return false;
    }

    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($proc) === 0;
}

/**
 * @param array<int, array{name:string,a:array,b:array}> $pairs
 *
 * @return array<int, array{name:string,equal:bool,aCount:int,bCount:int,witness:?string}>
 */
function equivalence_pairs(array $pairs, int $maxLen): array
{
    $job = @tempnam(sys_get_temp_dir(), 'gsb96eq');
    if ($job !== false) {
        $out = $job . '.out';
        @unlink($out);
        $payload = json_encode(['maxLen' => $maxLen, 'pairs' => $pairs]);
        if ($payload !== false && @file_put_contents($job, $payload) !== false && run_child_enum($job)) {
            $raw = @file_get_contents($out);
            $decoded = $raw === false ? null : json_decode($raw, true);
            @unlink($job);
            @unlink($out);
            if (is_array($decoded) && count($decoded) === count($pairs)) {
                return $decoded;
            }
        }
        @unlink($job);
        @unlink($out);
    }

    // 回退：进程内用同一份判据
    $fallback = [];
    foreach ($pairs as $pair) {
        $fallback[] = compare_pair($pair, $maxLen);
    }

    return $fallback;
}

// ---------------------------------------------------------------------------
// 子进程：规模轴（契约 10）
//
// 20 000 状态、字母表 4 的 DFA 交给独立子进程跑，父进程用看门狗兜底：
// 被测实现若是 O(n²) 填表法（或构造 Dfa 时用了线性查找），这里会超时而不是把整轮卡死。
// ---------------------------------------------------------------------------

/**
 * 规模轴的 DFA 规格：8 层，每层 per 个状态，字母表 4。
 * 同层状态两两 Myhill–Nerode 等价（转移是层内双射 + 每次读字符升一层，循环），
 * 接受集是最后一层，所以 8 层给出 8 个互不相同的语言 —— 最小结果恒为 8 个状态，
 * 而 Moore 划分恰好需要 8 轮左右收敛（远小于契约 10 的 64 轮上界）。
 */
function scale_fixture(int $levels, int $per): array
{
    $alphabet = SCALE_ALPHABET;
    $off = [];
    foreach ($alphabet as $i => $c) {
        $off[$c] = $i;
    }

    $n = $levels * $per;
    $states = [];
    for ($q = 0; $q < $n; $q++) {
        $states[] = $q;
    }

    $transitions = [];
    for ($l = 0; $l < $levels; $l++) {
        $base = $l * $per;
        $nextBase = (($l + 1) % $levels) * $per;
        for ($i = 0; $i < $per; $i++) {
            $row = [];
            foreach ($alphabet as $c) {
                $row[$c] = $nextBase + (($i + $off[$c]) % $per);
            }
            $transitions[$base + $i] = $row;
        }
    }

    $accepting = [];
    for ($q = ($levels - 1) * $per; $q < $n; $q++) {
        $accepting[] = $q;
    }

    return spec($states, $alphabet, $transitions, $accepting, 0);
}

/**
 * @return array{ok:bool,error?:string,n?:int,states?:int,refinements?:int,pairVisits?:int}
 */
function scale_run(int $n): array
{
    if ($n <= 0 || $n % SCALE_LEVELS !== 0) {
        return ['ok' => false, 'error' => '规模必须是 ' . SCALE_LEVELS . ' 的正整数倍'];
    }

    $fixture = scale_fixture(SCALE_LEVELS, intdiv($n, SCALE_LEVELS));

    try {
        $d = dfa_of($fixture);
        Meter::reset();
        $min = Minimizer::minimize($d, true)->toArray();
    } catch (\Throwable $exc) {
        return ['ok' => false, 'error' => get_class($exc) . ': ' . $exc->getMessage()];
    }

    return [
        'ok' => true,
        'n' => $n,
        'states' => count($min['states'] ?? []),
        'refinements' => Meter::$refinements,
        'pairVisits' => Meter::$pairVisits,
    ];
}

/**
 * 在子进程里跑规模场景；超时用看门狗兜底。返回值多一个 'timeout' 键。
 *
 * @return array{ok:bool,timeout?:bool,error?:string,n?:int,states?:int,refinements?:int,pairVisits?:int}
 */
function scale_probe(): array
{
    if (!function_exists('proc_open') || PHP_BINARY === '') {
        try {
            return scale_run(SCALE_STATES);
        } catch (\Throwable $exc) {
            return ['ok' => false, 'error' => get_class($exc) . ': ' . $exc->getMessage()];
        }
    }

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open([PHP_BINARY, __FILE__, '--_scale'], $descriptors, $pipes, __DIR__);
    if (!is_resource($proc)) {
        try {
            return scale_run(SCALE_STATES);
        } catch (\Throwable $exc) {
            return ['ok' => false, 'error' => get_class($exc) . ': ' . $exc->getMessage()];
        }
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out = '';
    $err = '';
    $deadline = microtime(true) + SCALE_TIMEOUT_SECONDS;
    $timedOut = false;

    while (true) {
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) > $deadline) {
            $timedOut = true;
            break;
        }
        usleep(20000);
    }

    if ($timedOut) {
        @proc_terminate($proc, 9);
        @proc_close($proc);

        return [
            'ok' => false,
            'timeout' => true,
            'error' => '超过 ' . (int) SCALE_TIMEOUT_SECONDS . " 秒仍未跑完（按本机标定）\n" . $err,
        ];
    }

    $out .= (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $decoded = json_decode(trim($out), true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => '子进程没有返回可解析的结果：' . trim($out . ' ' . $err)];
    }

    return $decoded;
}

// ---------------------------------------------------------------------------
// 子进程入口
// ---------------------------------------------------------------------------

$argv = $argv ?? [];

if (isset($argv[1]) && $argv[1] === '--_equivalence') {
    $jobFile = $argv[2] ?? '';
    $job = @json_decode((string) @file_get_contents($jobFile), true);
    if (!is_array($job) || !isset($job['pairs'], $job['maxLen'])) {
        fwrite(STDERR, "bad job\n");
        exit(2);
    }
    $results = [];
    foreach ($job['pairs'] as $pair) {
        $results[] = compare_pair($pair, (int) $job['maxLen']);
    }
    @file_put_contents($jobFile . '.out', json_encode($results));
    exit(0);
}

if (isset($argv[1]) && strncmp($argv[1], '--_scale', 7) === 0) {
    $n = SCALE_STATES;
    if (preg_match('/^--_scale=(\d+)$/', $argv[1], $m) === 1) {
        $n = (int) $m[1];
    }
    echo json_encode(scale_run($n));
    exit(0);
}

// ---------------------------------------------------------------------------
// 场景注册
// ---------------------------------------------------------------------------

$groups = [];

function add(string $group, string $name, callable $fn): void
{
    global $groups;
    $groups[$group][$name] = $fn;
}

// 长度 <= 7 全枚举用的几台自动机（覆盖：无缺失转移 / 稀疏缺失 / 链 / 空字母表 / 无接受 / 全接受）
$equivalenceDfas = [
    ['flat', spec([0, 1, 2, 3], ['a', 'b'], [
        0 => ['a' => 1, 'b' => 2],
        1 => ['a' => 0, 'b' => 3],
        2 => ['a' => 3, 'b' => 0],
        3 => ['a' => 2, 'b' => 1],
    ], [0, 2], 0)],
    ['sparse', spec([0, 1, 2], ['a', 'b'], [
        0 => ['a' => 1],
        1 => ['b' => 2],
    ], [2], 0)],
    ['chain', spec([0, 1, 2], ['a'], [
        0 => ['a' => 1],
        1 => ['a' => 2],
        2 => ['a' => 1],
    ], [1, 2], 0)],
    ['empty-alphabet', spec([0, 1], [], [], [1], 0)],
    ['no-accept', spec([0, 1], ['a'], [0 => ['a' => 1], 1 => ['a' => 0]], [], 0)],
    ['all-accept', spec([0, 1, 2], ['a', 'b'], [
        0 => ['a' => 1, 'b' => 2],
        1 => ['a' => 2, 'b' => 0],
        2 => ['a' => 0, 'b' => 1],
    ], [0, 1, 2], 0)],
];

// ---------------------------------------------------------------------------
// [build] 2
// ---------------------------------------------------------------------------

add('build', 'construct', static function (): void {
    // 状态号乱序入场：归一化后 states 必须升序，transitions 的每个状态键都要在。
    $in = spec(
        [2, 0, 1],
        ['a', 'b'],
        [0 => ['a' => 1, 'b' => 2], 1 => ['b' => 2], 2 => ['a' => 0]],
        [2],
        0
    );
    $want = spec(
        [0, 1, 2],
        ['a', 'b'],
        [0 => ['a' => 1, 'b' => 2], 1 => ['b' => 2], 2 => ['a' => 0]],
        [2],
        0
    );

    expect_same($want, dfa_of($in)->toArray(), '状态号乱序入场也要归一化成 0..n-1 升序，且「无出边」的状态键仍在表里');

    $d = dfa_of($in);
    expect_same(true, $d->accepts('ab'), '0 --a--> 1 --b--> 2（接受态）应被接受');
    expect_same(false, $d->accepts('ba'), '0 --b--> 2 --a--> 0（非接受态）应被拒绝');
    expect_same(false, $d->accepts(''), '起点 0 不是接受态，空串应被拒绝');
    expect_same(false, $d->accepts('aa'), '1 上没有 a 转移 -> 隐式死状态，应被拒绝');
    expect_same(true, $d->accepts('abab'), '0-a->1-b->2-a->0-b->2 应被接受');
    expect_same($want, $d->toArray(), '已构造过的对象再取一次 toArray 结构应稳定');

    // 单状态、内层空表的边界。
    $one = spec([0], ['a'], [0 => []], [], 0);
    expect_same($one, dfa_of($one)->toArray(), '单状态 + 内层空表应原样保留');
    expect_same(false, dfa_of($one)->accepts('a'), '单状态不接受态：读一个 a 就落隐式死状态');
    expect_same(2, dfa_of($in)->transition(0, 'b'), 'transition() 返回显式转移的目标');
    expect_same(null, dfa_of($in)->transition(1, 'a'), 'transition() 对缺失转移返回 null');

    // 非法入参：alphabet 重复 / 空字符 / 多字节字符 / transitions 用了表外字符 / 缺号 / accepting 越界。
    expect_input_error(spec([0], ['a', 'b', 'a'], [], [], 0), 'alphabet 里出现重复字符');
    expect_input_error(spec([0], ['a', ''], [], [], 0), 'alphabet 里出现空串');
    expect_input_error(spec([0], ['/é/'], [], [], 0), 'alphabet 元素不是单字节字符');
    expect_input_error(spec([0, 1], ['a'], [0 => ['z' => 1]], [], 0), 'transitions 用了 alphabet 表外的字符');
    expect_input_error(spec([0, 2], ['a'], [], [], 0), 'states 缺号（不是 0..n-1 的全集）');
    expect_input_error(spec([0, 1], ['a'], [], [1, 2], 0), 'accepting 里出现越界状态号');
});

add('build', 'range', static function (): void {
    expect_input_error(
        spec([0, 1, 2], ['a'], [0 => ['a' => 5]], [], 0),
        'transitions 的目标状态号 5 越界'
    );
    expect_input_error(
        spec([0, 1], ['a'], [7 => ['a' => 0]], [], 0),
        'transitions 的状态键 7 越界'
    );
    expect_input_error(
        spec([0, 1], ['a'], [0 => ['a' => -1]], [], 0),
        'transitions 的目标状态号 -1 越界'
    );
    expect_input_error(spec([0, 1], ['a'], [], [], 5), 'start = 5 不在 0..n-1 内');
    expect_input_error(spec([0, 1], ['a'], [], [], -1), 'start = -1 不在 0..n-1 内');
});

// ---------------------------------------------------------------------------
// [merge] 3
// ---------------------------------------------------------------------------

add('merge', 'accepting-split', static function (): void {
    // 状态 1（接受）与状态 2（非接受）转移完全相同：只看转移会误并，必须靠接受性挡住。
    $in = spec(
        [0, 1, 2],
        ['a', 'b'],
        [0 => ['a' => 1, 'b' => 2], 1 => ['a' => 1, 'b' => 1], 2 => ['a' => 1, 'b' => 1]],
        [1],
        0
    );
    $want = spec(
        [0, 1, 2],
        ['a', 'b'],
        [0 => ['a' => 1, 'b' => 2], 1 => ['a' => 1, 'b' => 1], 2 => ['a' => 1, 'b' => 1]],
        [1],
        0
    );

    expect_same($want, Minimizer::minimize(dfa_of($in), true)->toArray(), '接受态与非接受态转移相同也必须保持不同类');

    // 全部接受：0 与 1 接受同一语言（偶数长度串），应并成一个接受态。
    $all = spec([0, 1], ['a'], [0 => ['a' => 1], 1 => ['a' => 0]], [0, 1], 0);
    expect_same(
        spec([0], ['a'], [0 => ['a' => 0]], [0], 0),
        Minimizer::minimize(dfa_of($all), true)->toArray(),
        '全部接受：两个状态接受同一语言，应并成一个接受态'
    );
});

add('merge', 'unreachable', static function (): void {
    // 2、3 从起点根本走不到，必须先剪掉，绝不能出现在结果里。
    $in = spec(
        [0, 1, 2, 3],
        ['a'],
        [0 => ['a' => 1], 1 => ['a' => 1], 2 => ['a' => 2], 3 => ['a' => 3]],
        [1],
        0
    );
    $want = spec([0, 1], ['a'], [0 => ['a' => 1], 1 => ['a' => 1]], [1], 0);

    expect_same($want, Minimizer::minimize(dfa_of($in), true)->toArray(), '不可达状态 2、3 应先被移除');
});

add('merge', 'dead', static function (): void {
    // 2 与 3 都到不了接受态：pruneDead=true 并成一类后整体移除；false 时并成一类保留。
    $in = spec(
        [0, 1, 2, 3],
        ['a', 'b'],
        [
            0 => ['a' => 1, 'b' => 2],
            1 => ['a' => 1, 'b' => 3],
            2 => ['a' => 2, 'b' => 2],
            3 => ['a' => 2, 'b' => 2],
        ],
        [1],
        0
    );

    $pruned = spec([0, 1], ['a', 'b'], [0 => ['a' => 1], 1 => ['a' => 1]], [1], 0);
    $kept = spec(
        [0, 1, 2],
        ['a', 'b'],
        [0 => ['a' => 1, 'b' => 2], 1 => ['a' => 1, 'b' => 2], 2 => ['a' => 2, 'b' => 2]],
        [1],
        0
    );

    expect_same($pruned, Minimizer::minimize(dfa_of($in), true)->toArray(), 'pruneDead=true：两个死状态并成一类后应整体移除');
    expect_same($kept, Minimizer::minimize(dfa_of($in), false)->toArray(), 'pruneDead=false：两个死状态应并成一个等价类并保留');

    // 边界：无接受状态（语言为空）——true 退化成单起点且无转移，false 保留那个死状态类。
    $noAccept = spec([0, 1], ['a'], [0 => ['a' => 1], 1 => ['a' => 0]], [], 0);
    expect_same(
        spec([0], ['a'], [0 => []], [], 0),
        Minimizer::minimize(dfa_of($noAccept), true)->toArray(),
        '无接受状态：所有状态都到不了接受态，pruneDead=true 应退化成「只有起点、无转移、不接受」'
    );
    expect_same(
        spec([0], ['a'], [0 => ['a' => 0]], [], 0),
        Minimizer::minimize(dfa_of($noAccept), false)->toArray(),
        '无接受状态 + pruneDead=false：保留唯一那个死状态等价类'
    );

    // 边界：空字母表，起点不接受 —— 同样退化。
    $emptyAlphabet = spec([0, 1], [], [], [1], 0);
    expect_same(
        spec([0], [], [0 => []], [], 0),
        Minimizer::minimize(dfa_of($emptyAlphabet), true)->toArray(),
        '空字母表且起点不接受：只有起点可达，pruneDead=true 应退化成单起点'
    );
});

// ---------------------------------------------------------------------------
// [numbering] 2
// ---------------------------------------------------------------------------

add('numbering', 'reorder', static function (): void {
    // 等价类 {2,3} 的最小原编号是 2、{4} 的最小原编号是 4：重排后 {4} 应落到编号 3。
    $in = spec(
        [0, 1, 2, 3, 4],
        ['a', 'b'],
        [
            0 => ['a' => 2, 'b' => 3],
            1 => ['a' => 4, 'b' => 4],
            2 => ['a' => 1, 'b' => 1],
            3 => ['a' => 1, 'b' => 1],
            4 => ['a' => 4, 'b' => 4],
        ],
        [4],
        0
    );
    $want = spec(
        [0, 1, 2, 3],
        ['a', 'b'],
        [
            0 => ['a' => 2, 'b' => 2],
            1 => ['a' => 3, 'b' => 3],
            2 => ['a' => 1, 'b' => 1],
            3 => ['a' => 3, 'b' => 3],
        ],
        [3],
        0
    );

    expect_same($want, Minimizer::minimize(dfa_of($in), true)->toArray(), '含起点的类编号 0，其余按最小原编号升序重排');
});

add('numbering', 'idempotent', static function (): void {
    $in = spec([0, 1, 2], ['a'], [0 => ['a' => 1], 1 => ['a' => 2], 2 => ['a' => 2]], [1, 2], 0);

    $once = Minimizer::minimize(dfa_of($in), true);
    $twice = Minimizer::minimize($once, true);

    expect_same(2, count($once->toArray()['states']), '1 与 2 都是接受态且转移相同，应并成一个');
    expect_same($once->toArray(), $twice->toArray(), 'minimize 应幂等：再跑一次的 toArray 应逐字段相同');

    // 纯函数：minimize 不得修改入参。
    $d = dfa_of($in);
    $before = $d->toArray();
    Minimizer::minimize($d, false);
    expect_same($before, $d->toArray(), 'minimize 是纯函数，不得修改入参');
});

// ---------------------------------------------------------------------------
// [equivalence] 2
// ---------------------------------------------------------------------------

add('equivalence', 'language', static function (): void {
    global $equivalenceDfas;

    $pairs = [];
    foreach ($equivalenceDfas as [$name, $s]) {
        foreach ([true, false] as $prune) {
            $min = Minimizer::minimize(dfa_of($s), $prune)->toArray();
            $pairs[] = [
                'name' => $name . ' prune=' . ($prune ? 'true' : 'false'),
                'a' => $s,
                'b' => $min,
            ];
        }
    }

    $results = equivalence_pairs($pairs, 7);
    foreach ($results as $r) {
        if ($r['equal']) {
            continue;
        }
        throw new CheckFailure(
            $r['name'] . '：minimize 前后对所有长度 <= 7 的串接受性应完全一致',
            '一致',
            '不一致，首个反例 ' . show($r['witness']) . '（原 ' . $r['aCount'] . ' 个接受 / 最小化后 ' . $r['bCount'] . ' 个接受）'
        );
    }
});

add('equivalence', 'size', static function (): void {
    global $equivalenceDfas;

    $checked = 0;
    foreach ($equivalenceDfas as [$name, $s]) {
        foreach ([true, false] as $prune) {
            $want = ref_state_count($s, $prune);
            $got = count(Minimizer::minimize(dfa_of($s), $prune)->toArray()['states']);
            expect_same($want, $got, $name . ' prune=' . ($prune ? 'true' : 'false') . '：状态数应等于朴素 O(n²) 参照的等价类个数');
            $checked++;
        }
    }
    expect_true($checked === 12, '参照比对应覆盖 6 台自动机 x 2 种 pruneDead', '12 组');
});

// ---------------------------------------------------------------------------
// [complete] 2
// ---------------------------------------------------------------------------

add('complete', 'explicit', static function (): void {
    // 稀疏 DFA：缺 (0,b) (1,a) (2,a) (2,b) —— 补全成一个新状态 3，并让它对所有字符自环。
    $in = spec(
        [0, 1, 2],
        ['a', 'b'],
        [0 => ['a' => 1], 1 => ['b' => 2]],
        [2],
        0
    );
    $want = spec(
        [0, 1, 2, 3],
        ['a', 'b'],
        [
            0 => ['a' => 1, 'b' => 3],
            1 => ['a' => 3, 'b' => 2],
            2 => ['a' => 3, 'b' => 3],
            3 => ['a' => 3, 'b' => 3],
        ],
        [2],
        0
    );

    $d = dfa_of($in);
    $before = $d->toArray();
    $got = Minimizer::complete($d)->toArray();

    expect_same($want, $got, 'complete：隐式死状态显式化为编号 max+1 的新状态，并补全所有缺失转移');
    expect_same($before, $d->toArray(), 'complete 是纯函数，不得修改入参');

    // 补全后的转移必须是完整的（每个状态 x 每个字符都有显式转移），且不能依赖数组 hash 顺序。
    foreach ($got['transitions'] as $q => $row) {
        expect_same($got['alphabet'], array_keys($row), "状态 {$q} 的转移必须按字符表顺序、每个字符都在");
    }

    // 已经是完整转移的 DFA：complete 不新增状态，也不动结构。
    $full = spec([0, 1], ['a'], [0 => ['a' => 1], 1 => ['a' => 0]], [1], 0);
    expect_same($full, Minimizer::complete(dfa_of($full))->toArray(), '转移本来就完整：complete 不新增死状态');

    // 空字母表没有缺失转移 —— 同样不该凭空多出一个状态（注意结果里每个状态键都要在）。
    $emptyAlphabet = spec([0], [], [0 => []], [], 0);
    expect_same($emptyAlphabet, Minimizer::complete(dfa_of($emptyAlphabet))->toArray(), '空字母表：complete 不新增死状态');
});

add('complete', 'language', static function (): void {
    global $equivalenceDfas;

    // (1) minimize(complete($d), true) 与 minimize($d, true) 必须语言等价：长度 <= 7 全枚举 + equivalent()。
    $pairs = [];
    foreach ($equivalenceDfas as [$name, $s]) {
        $completed = Minimizer::complete(dfa_of($s))->toArray();
        $pairs[] = [
            'name' => $name,
            'a' => $s,
            'b' => $completed,
        ];
        $pairs[] = [
            'name' => $name . ' minimize/complete 后',
            'a' => Minimizer::minimize(dfa_of($s), true)->toArray(),
            'b' => Minimizer::minimize(dfa_of($completed), true)->toArray(),
        ];
    }

    $results = equivalence_pairs($pairs, 7);
    foreach ($results as $r) {
        if ($r['equal']) {
            continue;
        }
        throw new CheckFailure(
            $r['name'] . '：补全前后（以及各自 minimize 之后）对所有长度 <= 7 的串接受性应完全一致',
            '一致',
            '不一致，首个反例 ' . show($r['witness']) . '（原 ' . $r['aCount'] . ' 个接受 / 补全后 ' . $r['bCount'] . ' 个接受）'
        );
    }

    // (2) equivalent()：与「minimize 结果同构」严格咬合（契约 9）。
    foreach ($equivalenceDfas as [$name, $s]) {
        $d = dfa_of($s);
        $completed = Minimizer::complete($d);

        expect_true(
            Minimizer::equivalent($d, $completed),
            $name . '：$d 与 complete($d) 语言相同'
        );
        expect_true(
            Minimizer::equivalent(Minimizer::minimize($d, true), Minimizer::minimize($completed, true)),
            $name . '：minimize($d) 与 minimize(complete($d)) 语言相同'
        );

        // 两种 pruneDead 下的最小化结果语言也相同（只是表示不同）。
        expect_true(
            Minimizer::equivalent(Minimizer::minimize($d, true), Minimizer::minimize($d, false)),
            $name . '：pruneDead 两种取值的 minimize 结果语言相同'
        );
    }

    // (3) 两两组合（同一个字母表 ['a','b']）：equivalent($x,$y) 必须当且仅当 minimize 结果「重排后同构」。
    $partial = spec([0, 1], ['a', 'b'], [
        0 => ['a' => 1, 'b' => 0],
        1 => ['a' => 0],
    ], [1], 0);

    $matrix = [
        'even-a' => spec([0, 1], ['a', 'b'], [
            0 => ['a' => 1, 'b' => 0],
            1 => ['a' => 0, 'b' => 1],
        ], [0], 0),
        'even-a-padded' => spec([0, 1, 2], ['a', 'b'], [
            0 => ['a' => 1, 'b' => 0],
            1 => ['a' => 0, 'b' => 1],
            2 => ['a' => 1, 'b' => 2],
        ], [0, 2], 0),
        'partial' => $partial,
        'partial-complete' => Minimizer::complete(dfa_of($partial))->toArray(),
        'empty' => spec([0, 1], ['a', 'b'], [
            0 => ['a' => 1, 'b' => 1],
            1 => ['a' => 0, 'b' => 0],
        ], [], 0),
        'full' => spec([0, 1], ['a', 'b'], [
            0 => ['a' => 1, 'b' => 1],
            1 => ['a' => 0, 'b' => 0],
        ], [0, 1], 0),
    ];

    $mins = [];
    foreach ($matrix as $name => $s) {
        $mins[$name] = Minimizer::minimize(dfa_of($s), true)->toArray();
    }

    $names = array_keys($matrix);
    $count = count($names);
    for ($i = 0; $i < $count; $i++) {
        for ($j = 0; $j < $count; $j++) {
            $x = $names[$i];
            $y = $names[$j];
            $iso = canon($mins[$x]) === canon($mins[$y]);
            $got = Minimizer::equivalent(dfa_of($mins[$x]), dfa_of($mins[$y]));

            expect_same(
                $iso,
                $got,
                "equivalent({$x}, {$y}) 应等于「两者 minimize 后同构」（"
                . canon($mins[$x]) . ' vs ' . canon($mins[$y]) . '）'
            );
        }
    }

    // (4) 同一台自动机 minimize 前后的语言也必须相同（equivalent 的自洽性）。
    foreach ($matrix as $name => $s) {
        expect_true(
            Minimizer::equivalent(dfa_of($s), Minimizer::minimize(dfa_of($s), true)),
            $name . '：原 DFA 与 minimize 结果的 equivalent 必须为 true'
        );
    }

    // (4) 字母表不同的两个 DFA：语义未定义，必须报 InputError。
    expect_input_error_from(
        static function (): void {
            Minimizer::equivalent(
                dfa_of(spec([0], ['a'], [0 => ['a' => 0]], [0], 0)),
                dfa_of(spec([0], ['b'], [0 => ['b' => 0]], [0], 0))
            );
        },
        'alphabet 不同的两个 DFA 做 equivalent'
    );
});

// ---------------------------------------------------------------------------
// [scale] 1
// ---------------------------------------------------------------------------

add('scale', 'budget', static function (): void {
    $r = scale_probe();

    if (!empty($r['timeout'])) {
        throw new CheckFailure(
            '20 000 状态下的 minimize 应在看门狗上限内跑完',
            '不超过 ' . (int) SCALE_TIMEOUT_SECONDS . ' 秒',
            '超时（疑似 O(n²) 算法或构造期线性查找）'
        );
    }

    if (empty($r['ok'])) {
        throw new CheckFailure(
            '20 000 状态下的 minimize 应正常返回',
            '正常返回',
            (string) ($r['error'] ?? '子进程没给出结果')
        );
    }

    expect_same(SCALE_LEVELS, (int) $r['states'], '8 层结构的最小化结果应恰好 ' . SCALE_LEVELS . ' 个状态');
    expect_true((int) $r['refinements'] >= 1, '划分至少发生过一次变化（Meter::$refinements 必须被真正累加）', '>= 1');

    if ((int) $r['refinements'] >= 64) {
        throw new CheckFailure(
            'Meter::$refinements 必须 < 64',
            '< 64',
            (string) $r['refinements']
        );
    }

    $cap = 20 * (int) $r['n'];
    if ((int) $r['pairVisits'] >= $cap) {
        throw new CheckFailure(
            'Meter::$pairVisits 必须 < 20 x states',
            '< ' . $cap,
            (string) $r['pairVisits']
        );
    }
});

// ---------------------------------------------------------------------------
// 参数解析 + 运行
// ---------------------------------------------------------------------------

$flat = [];
foreach ($groups as $group => $scenarios) {
    foreach ($scenarios as $name => $fn) {
        $flat[] = [$group, $name, $fn];
    }
}

$only = null;
$showList = false;

for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '-list' || $arg === '--list') {
        $showList = true;
    } elseif ($arg === '--only' || $arg === '--group') {
        $i++;
        if ($i >= count($argv)) {
            fwrite(STDERR, "--only 需要一个组名（build/merge/numbering/equivalence/complete/scale）\n");
            exit(1);
        }
        $only = $only ?? [];
        foreach (explode(',', $argv[$i]) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $only[$part] = true;
            }
        }
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "用法: php check/check.php [-list] [--only <组名>]\n";
        exit(0);
    } else {
        fwrite(STDERR, "无法识别的参数: {$arg}\n");
        exit(1);
    }
}

if ($showList) {
    foreach ($flat as [$group, $name, $_fn]) {
        echo $group . '/' . $name . "\n";
    }
    exit(0);
}

$selected = [];
foreach ($flat as $entry) {
    if ($only === null || isset($only[$entry[0]])) {
        $selected[] = $entry;
    }
}

$passed = 0;

foreach ($selected as [$group, $name, $fn]) {
    try {
        $fn();
    } catch (CheckFailure $exc) {
        printf("FAIL %s/%s  期望=%s 实际=%s（%s）\n", $group, $name, $exc->expected, $exc->actual, $exc->getMessage());
        continue;
    } catch (\Throwable $exc) {
        printf("FAIL %s/%s  期望=正常返回 实际=%s: %s\n", $group, $name, get_class($exc), $exc->getMessage());
        continue;
    }

    $passed++;
    printf("PASS %s/%s\n", $group, $name);
}

$total = count($selected);
printf("结果：通过 %d/%d\n", $passed, $total);

exit($passed === $total && $total > 0 ? 0 : 1);
