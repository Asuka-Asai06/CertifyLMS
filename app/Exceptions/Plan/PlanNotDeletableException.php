<?php

declare(strict_types=1);

namespace App\Exceptions\Plan;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 削除条件を満たさないプランを削除しようとした際の例外（HTTP 409）。
 */
final class PlanNotDeletableException extends ConflictHttpException
{
    public function __construct(
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $previous);
    }
}
