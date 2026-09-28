<?php

declare(strict_types=1);

/**
 * 规模轴的计数器（契约 10）。**固定入口：字段名与语义都别改。**
 *
 * 固定件在每次调用被测实现之前先 `Meter::reset()`，调用之后读这两个字段。
 * 语义（README「对外契约」第 10 条）：
 *
 *   $refinements —— 划分发生变化的次数。初始划分（按接受性分成两类）不计；
 *                   此后每让等价类集合多出至少一个新类，就 +1。
 *   $pairVisits  —— 状态对等价性考察次数。每把两个状态号放在一起比较
 *                   （填表法的内层循环、两两对比等）就 +1。
 *
 * 两者都是 public static int，由实现自行累加；固定件只读不写。
 */
final class Meter
{
    /** 划分变化的次数。 */
    public static int $refinements = 0;

    /** 状态对等价性考察次数。 */
    public static int $pairVisits = 0;

    /** 固定件在每次测量前调用，把两个计数器清零。 */
    public static function reset(): void
    {
        self::$refinements = 0;
        self::$pairVisits = 0;
    }
}
