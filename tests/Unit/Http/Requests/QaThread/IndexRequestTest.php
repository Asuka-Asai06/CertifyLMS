<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\QaThread;

use App\Http\Requests\QaThread\IndexRequest;
use App\Models\Certification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 質問掲示板スレッド一覧 IndexRequest の rules() を検証する Unit テスト。
 * certification_id (ulid + exists) / status (in:unresolved,resolved) /
 * keyword (max:100) / page (integer + min:1) を網羅する。
 */
class IndexRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_with_empty_filters(): void
    {
        $validator = Validator::make(
            [],
            (new IndexRequest)->rules(),
        );

        $this->assertTrue($validator->passes());
    }

    public function test_passes_with_valid_filters(): void
    {
        $certification = Certification::factory()->create();

        $validator = Validator::make(
            [
                'certification_id' => $certification->id,
                'status' => 'unresolved',
                'keyword' => 'Laravel',
                'page' => 1,
            ],
            (new IndexRequest)->rules(),
        );

        $this->assertTrue($validator->passes());
    }

    public function test_fails_when_certification_id_not_ulid(): void
    {
        $validator = Validator::make(
            ['certification_id' => 'not-ulid'],
            (new IndexRequest)->rules(),
        );

        $this->assertArrayHasKey(
            'certification_id',
            $validator->errors()->toArray(),
        );
    }

    public function test_fails_when_certification_id_not_exists(): void
    {
        $validator = Validator::make(
            ['certification_id' => '01j00000000000000000000000'],
            (new IndexRequest)->rules(),
        );

        $this->assertArrayHasKey(
            'certification_id',
            $validator->errors()->toArray(),
        );
    }

    public function test_fails_when_status_invalid(): void
    {
        $validator = Validator::make(
            ['status' => 'invalid'],
            (new IndexRequest)->rules(),
        );

        $this->assertArrayHasKey(
            'status',
            $validator->errors()->toArray(),
        );
    }

    public function test_fails_when_keyword_not_string(): void
    {
        $validator = Validator::make(
            ['keyword' => ['Laravel']],
            (new IndexRequest)->rules(),
        );

        $this->assertArrayHasKey(
            'keyword',
            $validator->errors()->toArray(),
        );
    }

    public function test_fails_when_keyword_too_long(): void
    {
        $validator = Validator::make(
            ['keyword' => str_repeat('a', 101)],
            (new IndexRequest)->rules(),
        );

        $this->assertArrayHasKey(
            'keyword',
            $validator->errors()->toArray(),
        );
    }

    public function test_fails_when_page_not_integer(): void
    {
        $validator = Validator::make(
            ['page' => 'first'],
            (new IndexRequest)->rules(),
        );

        $this->assertArrayHasKey(
            'page',
            $validator->errors()->toArray(),
        );
    }

    public function test_fails_when_page_zero(): void
    {
        $validator = Validator::make(
            ['page' => 0],
            (new IndexRequest)->rules(),
        );

        $this->assertArrayHasKey(
            'page',
            $validator->errors()->toArray(),
        );
    }

    public function test_filters_returns_expected_values(): void
    {
        $request = new IndexRequest;

        $request->merge([
            'certification_id' => '01j00000000000000000000000',
            'status' => 'resolved',
            'keyword' => 'Laravel',
        ]);

        $this->assertSame(
            [
                'certification_id' => '01j00000000000000000000000',
                'status' => 'resolved',
                'keyword' => 'Laravel',
            ],
            $request->filters(),
        );
    }
}
