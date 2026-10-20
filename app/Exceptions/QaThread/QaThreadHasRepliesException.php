<?php

declare(strict_types=1);

namespace App\Exceptions\QaThread;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class QaThreadHasRepliesException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('回答が付いているスレッドは削除できません。');
    }
}
