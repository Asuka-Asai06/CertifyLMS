<?php

declare(strict_types=1);

namespace Tests;

use App\Services\GoogleCalendarService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel HTTP Client のモックされていない通信を禁止する。
        Http::preventStrayRequests();

        // GoogleCalendarService のデフォルト通信を禁止する。
        // 明示的にモックを注入したテストでは、そのモックが優先される。
        $httpClient = new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler),
        ]);

        $this->app->instance(
            GoogleCalendarService::class,
            new GoogleCalendarService($httpClient),
        );
    }
}
