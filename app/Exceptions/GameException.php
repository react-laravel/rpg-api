<?php

namespace App\Exceptions;

use Exception;
use Throwable;

class GameException extends Exception
{
    /**
     * 错误代码常量
     */
    public const CODE_CHARACTER_NOT_FOUND = 1001;

    public const CODE_INSUFFICIENT_LEVEL = 1002;

    public const CODE_INSUFFICIENT_RESOURCES = 1003;

    public const CODE_COMBAT_NOT_IN_PROGRESS = 1004;

    public const CODE_INVALID_SKILL = 1005;

    public const CODE_SKILL_ON_COOLDOWN = 1006;

    public const CODE_INSUFFICIENT_MANA = 1007;

    public const CODE_INVALID_OPERATION = 1008;

    public const CODE_MAP_NOT_FOUND = 1009;

    public const CODE_MONSTER_NOT_FOUND = 1010;

    public const CODE_INVALID_DIFFICULTY = 1011;

    /**
     * 自定义错误消息
     *
     * @var array<int, string>
     */
    private static array $messages = [
        self::CODE_CHARACTER_NOT_FOUND => 'Character not found',
        self::CODE_INSUFFICIENT_LEVEL => 'Insufficient level',
        self::CODE_INSUFFICIENT_RESOURCES => 'Insufficient resources',
        self::CODE_COMBAT_NOT_IN_PROGRESS => 'Combat is not in progress',
        self::CODE_INVALID_SKILL => 'Invalid skill',
        self::CODE_SKILL_ON_COOLDOWN => 'Skill is on cooldown',
        self::CODE_INSUFFICIENT_MANA => 'Insufficient mana',
        self::CODE_INVALID_OPERATION => 'Invalid operation',
        self::CODE_MAP_NOT_FOUND => 'Map not found',
        self::CODE_MONSTER_NOT_FOUND => 'Monster not found',
        self::CODE_INVALID_DIFFICULTY => 'Invalid difficulty tier',
    ];

    /**
     * @param  int  $code  错误代码
     * @param  string|null  $message  自定义错误消息
     * @param  Throwable|null  $previous  上一级异常
     */
    public function __construct(
        int $code,
        ?string $message = null,
        ?Throwable $previous = null
    ) {
        $message = $message ?? (self::$messages[$code] ?? 'Unknown game error');

        parent::__construct($message, $code, $previous);
    }

    /**
     * 获取错误代码
     */
    public function getErrorCode(): int
    {
        return $this->getCode();
    }

    /**
     * 静态工厂方法：角色未找到
     */
    public static function characterNotFound(?string $message = null, ?Throwable $previous = null): self
    {
        return new self(self::CODE_CHARACTER_NOT_FOUND, $message, $previous);
    }

    /**
     * 静态工厂方法：等级不足
     */
    public static function insufficientLevel(?string $message = null, ?Throwable $previous = null): self
    {
        return new self(self::CODE_INSUFFICIENT_LEVEL, $message, $previous);
    }

    /**
     * 静态工厂方法：资源不足
     */
    public static function insufficientResources(?string $message = null, ?Throwable $previous = null): self
    {
        return new self(self::CODE_INSUFFICIENT_RESOURCES, $message, $previous);
    }

    /**
     * 静态工厂方法：战斗未开始
     */
    public static function combatNotInProgress(?string $message = null, ?Throwable $previous = null): self
    {
        return new self(self::CODE_COMBAT_NOT_IN_PROGRESS, $message, $previous);
    }

    /**
     * 静态工厂方法：无效技能
     */
    public static function invalidSkill(?string $message = null, ?Throwable $previous = null): self
    {
        return new self(self::CODE_INVALID_SKILL, $message, $previous);
    }

    /**
     * 静态工厂方法：技能冷却中
     */
    public static function skillOnCooldown(?string $message = null, ?Throwable $previous = null): self
    {
        return new self(self::CODE_SKILL_ON_COOLDOWN, $message, $previous);
    }

    /**
     * 静态工厂方法：魔法值不足
     */
    public static function insufficientMana(?string $message = null, ?Throwable $previous = null): self
    {
        return new self(self::CODE_INSUFFICIENT_MANA, $message, $previous);
    }

    /**
     * 静态工厂方法：无效操作
     */
    public static function invalidOperation(?string $message = null, ?Throwable $previous = null): self
    {
        return new self(self::CODE_INVALID_OPERATION, $message, $previous);
    }

    /**
     * 静态工厂方法：地图未找到
     */
    public static function mapNotFound(?string $message = null, ?Throwable $previous = null): self
    {
        return new self(self::CODE_MAP_NOT_FOUND, $message, $previous);
    }

    /**
     * 静态工厂方法：怪物未找到
     */
    public static function monsterNotFound(?string $message = null, ?Throwable $previous = null): self
    {
        return new self(self::CODE_MONSTER_NOT_FOUND, $message, $previous);
    }

    /**
     * 静态工厂方法：无效难度
     */
    public static function invalidDifficulty(?string $message = null, ?Throwable $previous = null): self
    {
        return new self(self::CODE_INVALID_DIFFICULTY, $message, $previous);
    }

    /**
     * 转换为 JSON 响应数组
     *
     * @return array{success: bool, code: int, message: string}
     */
    public function toResponseArray(): array
    {
        return [
            'success' => false,
            'code' => $this->getErrorCode(),
            'message' => $this->getMessage(),
        ];
    }
}
