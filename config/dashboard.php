<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Admin dashboard aggregate cache
    |--------------------------------------------------------------------------
    |
    | 管理者ダッシュボードの集計キャッシュ設定。
    | 資格の公開状態変更による即時無効化は行わず、TTL による期限切れを利用する。
    |
    */

    'admin_kpi_cache_key' => 'dashboard.admin_kpi',

    'admin_completion_rate_cache_key' => 'dashboard.admin_completion_rate',

    'admin_stats_cache_ttl' => (int) env('DASHBOARD_ADMIN_STATS_CACHE_TTL', 300),
];
