<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

abstract class BaseService
{
    /**
     * 返回成功结果
     */
    protected function success(array $data = [], string $message = 'Success'): array
    {
        $result = ['success' => true, 'message' => $message];

        return array_merge($result, $data);
    }

    /**
     * 返回错误结果
     */
    protected function error(string $message, array $errors = []): array
    {
        $result = ['success' => false, 'message' => $message];
        if (! empty($errors)) {
            $result['errors'] = $errors;
        }

        return $result;
    }

    /**
     * 记录错误日志
     */
    protected function logError(string $message, array $context = []): void
    {
        Log::error($message, array_merge($context, [
            'service' => static::class,
            'timestamp' => now()->toISOString(),
        ]));
    }

    /**
     * 记录信息日志
     */
    protected function logInfo(string $message, array $context = []): void
    {
        Log::info($message, array_merge($context, [
            'service' => static::class,
            'timestamp' => now()->toISOString(),
        ]));
    }

    /**
     * 清理和验证字符串
     */
    protected function sanitizeString(string $input): string
    {
        return trim(strip_tags($input));
    }

    /**
     * 验证字符串长度
     */
    protected function validateStringLength(string $input, int $min, int $max, string $fieldName = 'field'): array
    {
        $length = strlen($input);
        $errors = [];

        if ($length < $min) {
            $errors[] = "{$fieldName} must be at least {$min} characters";
        }

        if ($length > $max) {
            $errors[] = "{$fieldName} cannot exceed {$max} characters";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * 处理异常并返回错误结果
     */
    protected function handleException(\Throwable $e, string $operation = 'operation'): array
    {
        $this->logError("Failed to {$operation}", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return $this->error("Failed to {$operation}");
    }
}
