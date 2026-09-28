<?php

declare(strict_types=1);

/**
 * 确定性有限自动机（契约 1、11、12）。
 *
 * 构造入参：
 *   - $states      int[]，状态编号集合，必须恰好是 0..n-1 这 n 个整数（顺序随意，集合必须齐）；
 *   - $alphabet    string[]，**有序**字符数组，元素为单个字符（单字节），不得重复；
 *   - $transitions array<int, array<string, int>>，state => char => state；
 *                  出现的 state 键、字符、目标状态号都必须合法；
 *                  缺失的 (state, char) 即隐式死状态；
 *   - $accepting   int[]，接受状态编号集合，必须是 $states 的子集；
 *   - $start       int，起点，必须是 $states 之一。
 *
 * 任一入参非法 -> InputError；对象构造完成后不可变（纯函数，契约 12）。
 */
final class Dfa
{
    /**
     * @param int[]                          $states
     * @param string[]                       $alphabet
     * @param array<int, array<string, int>> $transitions
     * @param int[]                          $accepting
     */
    public function __construct(
        array $states,
        array $alphabet,
        array $transitions,
        array $accepting,
        int $start
    ) {
        throw new RuntimeException('not implemented');
    }

    /** @return int[] 状态编号，升序。 */
    public function getStates(): array
    {
        throw new RuntimeException('not implemented');
    }

    /** @return string[] 字符表，保持构造时的顺序。 */
    public function getAlphabet(): array
    {
        throw new RuntimeException('not implemented');
    }

    /**
     * 显式给出的转移表。
     *
     * @return array<int, array<string, int>> state => char => state；
     *         键按 state 升序、每个 state 内按字符表顺序排列。
     */
    public function getTransitions(): array
    {
        throw new RuntimeException('not implemented');
    }

    /** @return int[] 接受状态编号，升序。 */
    public function getAccepting(): array
    {
        throw new RuntimeException('not implemented');
    }

    public function getStart(): int
    {
        throw new RuntimeException('not implemented');
    }

    /**
     * 单个转移的目标状态号；该 (state, char) 未显式给出时返回 null（隐式死状态）。
     */
    public function transition(int $state, string $char): ?int
    {
        throw new RuntimeException('not implemented');
    }

    /**
     * 从起点读完整串后，是否停在接受状态。
     * 读入过程中只要撞上缺失的 (state, char) 就落到隐式死状态，从此永远拒绝。
     */
    public function accepts(string $input): bool
    {
        throw new RuntimeException('not implemented');
    }

    /**
     * 归一化输出（契约 11）：
     *   states 升序、alphabet 按给定顺序、transitions 按 state 升序 + 字符表顺序
     *   （结果里的每个状态都出现在表中，无出边则为空表）、accepting 升序、start。
     *
     * @return array{
     *     states: int[],
     *     alphabet: string[],
     *     transitions: array<int, array<string, int>>,
     *     accepting: int[],
     *     start: int
     * }
     */
    public function toArray(): array
    {
        throw new RuntimeException('not implemented');
    }
}
