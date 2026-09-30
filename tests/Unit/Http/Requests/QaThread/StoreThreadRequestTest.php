<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\QaThread;

use App\Http\Requests\QaThread\StoreThreadRequest;
use App\Models\Certification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 質問掲示板スレッド新規作成 StoreThreadRequest の rules() を検証する Unit テスト。
 * certification_id (required + ulid + exists) / title (required + max:200) /
 * body (required + max:5000) を網羅する。
 */
class StoreThreadRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_with_valid_payload(): void
    {
        $certification = Certification::factory()
            ->published()
            ->create();

        $validator = Validator::make([
            'certification_id' => $certification->id,
            'title' => 'Laravelについて質問があります',
            'body' => 'Policyの使い方について教えてください。',
        ], (new StoreThreadRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_fails_when_certification_id_missing(): void
    {
        $validator = Validator::make([
            'title' => 'Laravelについて質問があります',
            'body' => 'Policyの使い方について教えてください。',
        ], (new StoreThreadRequest)->rules());

        $this->assertArrayHasKey(
            'certification_id',
            $validator->errors()->toArray(),
        );
    }

    public function test_fails_when_certification_id_not_ulid(): void
    {
        $validator = Validator::make([
            'certification_id' => 'invalid-id',
            'title' => 'Laravelについて質問があります',
            'body' => 'Policyの使い方について教えてください。',
        ], (new StoreThreadRequest)->rules());

        $this->assertArrayHasKey(
            'certification_id',
            $validator->errors()->toArray(),
        );
    }

    public function test_fails_when_certification_id_not_exists(): void
    {
        $validator = Validator::make([
            'certification_id' => '01j00000000000000000000000',
            'title' => 'Laravelについて質問があります',
            'body' => 'Policyの使い方について教えてください。',
        ], (new StoreThreadRequest)->rules());

        $this->assertArrayHasKey(
            'certification_id',
            $validator->errors()->toArray(),
        );
    }

    public function test_fails_when_title_missing(): void
    {
        $certification = Certification::factory()->create();

        $validator = Validator::make([
            'certification_id' => $certification->id,
            'body' => 'Policyの使い方について教えてください。',
        ], (new StoreThreadRequest)->rules());

        $this->assertArrayHasKey(
            'title',
            $validator->errors()->toArray(),
        );
    }

    public function test_fails_when_title_too_long(): void
    {
        $certification = Certification::factory()->create();

        $validator = Validator::make([
            'certification_id' => $certification->id,
            'title' => str_repeat('a', 201),
            'body' => 'Policyの使い方について教えてください。',
        ], (new StoreThreadRequest)->rules());

        $this->assertArrayHasKey(
            'title',
            $validator->errors()->toArray(),
        );
    }

    public function test_fails_when_body_missing(): void
    {
        $certification = Certification::factory()->create();

        $validator = Validator::make([
            'certification_id' => $certification->id,
            'title' => 'Laravelについて質問があります',
        ], (new StoreThreadRequest)->rules());

        $this->assertArrayHasKey(
            'body',
            $validator->errors()->toArray(),
        );
    }

    public function test_fails_when_body_too_long(): void
    {
        $certification = Certification::factory()->create();

        $validator = Validator::make([
            'certification_id' => $certification->id,
            'title' => 'Laravelについて質問があります',
            'body' => str_repeat('a', 5001),
        ], (new StoreThreadRequest)->rules());

        $this->assertArrayHasKey(
            'body',
            $validator->errors()->toArray(),
        );
    }
}
