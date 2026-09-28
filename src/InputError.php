<?php

declare(strict_types=1);

/**
 * 入参非法时抛出的异常（契约 12）。
 *
 * 所有非法参数都必须以 InputError 结束，不得让原生 TypeError / Warning 泄漏出去。
 * 这是一个纯异常类型，没有需要实现的方法体。
 */
class InputError extends RuntimeException
{
}
