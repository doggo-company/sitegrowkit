<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use AgentCms\Core\Uploads\UploadValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ImageUploadSizePolicyTest extends TestCase
{
    public function test_an_image_of_twenty_megabytes_is_allowed(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('limit.jpg')->size(20 * 1024);
        $path = app(UploadValidator::class)->store($file);
        Storage::disk('public')->assertExists($path);
    }

    public function test_an_image_above_twenty_megabytes_has_a_readable_limit(): void
    {
        $file = UploadedFile::fake()->image('large.jpg')->size(20 * 1024 + 1);
        try {
            app(UploadValidator::class)->store($file);
            $this->fail('An oversized image was accepted');
        } catch (ValidationException $error) {
            $this->assertSame(['画像は20MBまでアップロードできます。'], $error->errors()['file']);
        }
    }

    public function test_request_limits_allow_multipart_overhead_above_the_file_limit(): void
    {
        $root = base_path();
        $php = parse_ini_file($root.'/docker/php/php.ini');
        $nginx = file_get_contents($root.'/docker/nginx/default.conf');
        $this->assertIsArray($php);
        $this->assertIsString($nginx);
        $this->assertSame('20M', $php['upload_max_filesize']);
        $this->assertSame('25M', $php['post_max_size']);
        $this->assertStringContainsString('client_max_body_size 25m;', $nginx);
        $this->assertSame(20480, config('agentcms.uploads.max_kilobytes'));
    }
}
