<?php

declare(strict_types=1);

/**
 * DFA 最小化、补全与语言等价判定（契约 2~10）。
 *
 * 三个方法都是纯函数：不修改入参，结果与 PHP 数组 hash 顺序无关。
 */
final class Minimizer
{
    /**
     * 返回最小化后的新 DFA；不修改 $d（纯函数，契约 12）。
     *
     * @param bool $pruneDead true：把所有「不导向任何接受状态」的非接受状态并成一类后**移除**；
     *                        false：保留该类。
     */
    public static function minimize(Dfa $d, bool $pruneDead = true): Dfa
    {
        throw new RuntimeException('not implemented');
    }

    /**
     * 把隐式死状态**显式化**（契约 8）。
     *
     * 只要 $d 里存在至少一处缺失转移，就新增一个状态（编号 = 当前最大编号 + 1），
     * 把所有缺失转移补到它，并让它对所有字符自环；它不接受任何串。
     * 若 $d 的转移本来就是完整的，则等价于返回一份归一化副本（不新增状态）。
     */
    public static function complete(Dfa $d): Dfa
    {
        throw new RuntimeException('not implemented');
    }

    /**
     * 判定两个 DFA 的语言是否相同（契约 9）。
     */
    public static function equivalent(Dfa $a, Dfa $b): bool
    {
        throw new RuntimeException('not implemented');
    }
}
