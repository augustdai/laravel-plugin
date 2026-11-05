<?php

namespace Jyim\LaravelPlugin\Enums;

use JsonSerializable;

/**
 * 插件启用状态。
 *
 * 原实现继承 `Spatie\Enum\Laravel\Enum`，该包只支持到 Laravel 8/9。
 * 这里只用到极小一部分能力（make / equals / 取原始值 / 转字符串），
 * 因此改为零依赖的自包含实现，避免为一个 10 行的枚举拖进两个包。
 *
 * `$value` 保持 public：调用方（InstallPlugin::setStatusAttribute）直接读 `$value->value`。
 */
class PluginStatus implements JsonSerializable
{
    public const DISABLE = 0;
    public const ENABLE = 1;

    /** @var array<string,int> */
    private const MAP = [
        'disable' => self::DISABLE,
        'enable' => self::ENABLE,
    ];

    /** @var int */
    public $value;

    public function __construct(int $value)
    {
        $this->value = $value;
    }

    public static function enable(): self
    {
        return new self(self::ENABLE);
    }

    public static function disable(): self
    {
        return new self(self::DISABLE);
    }

    public static function make($value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value) && isset(self::MAP[$value])) {
            return new self(self::MAP[$value]);
        }

        return new self((int) $value);
    }

    public function equals($other): bool
    {
        if ($other instanceof self) {
            return $this->value === $other->value;
        }

        return $this->value === (int) $other;
    }

    public function toArray(): int
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }

    public function jsonSerialize(): int
    {
        return $this->value;
    }
}