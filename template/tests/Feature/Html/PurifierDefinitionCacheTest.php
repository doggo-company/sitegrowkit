<?php

declare(strict_types=1);

namespace Tests\Feature\Html;

use AgentCms\Core\Html\Purifier;
use AgentCms\Core\Html\RestrictedHtml;
use HTMLPurifier;
use HTMLPurifier_Config;
use HTMLPurifier_DefinitionCache_Serializer;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 本文の整え方（HTMLPurifier）は、定義のキャッシュを使わない。
 *
 * HTMLPurifier はキャッシュを unserialize で読み込む。置き場所が storage（サイトが書ける）だと、
 * 乗っ取られたサイトが置いた定義（script を許す物）や PHP のオブジェクトを、出し直したあとも
 * 読み込んでしまう。storage に同じ名前の記録を置いても整え方が変わらないこと、
 * 整えても storage に記録を作らないことを見る。
 */
final class PurifierDefinitionCacheTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/sgk-purifier-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->storage.'/app');
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    /** @return array<string, array{class-string}> */
    public static function sanitizers(): array
    {
        return ['richtext（Purifier）' => [Purifier::class], 'HTML Component（RestrictedHtml）' => [RestrictedHtml::class]];
    }

    #[DataProvider('sanitizers')]
    public function test_storage_に置かれた定義の記録を読まない(string $class): void
    {
        $real = $this->configOf(new $class);
        $key = (new HTMLPurifier_DefinitionCache_Serializer('HTML'))->generateKey($real);
        $fake = HTMLPurifier_Config::createDefault();
        $fake->loadArray($real->getAll());
        $fake->set('HTML.Trusted', true);
        $fake->set('HTML.Allowed', $real->get('HTML.Allowed').',script');
        $fake->set('Cache.DefinitionImpl', null);
        File::ensureDirectoryExists($this->storage.'/app/purifier/HTML');
        file_put_contents($this->storage."/app/purifier/HTML/{$key}.ser", serialize($fake->getHTMLDefinition()));

        $out = (new $class)->clean('<p>e2e<script>alert(1)</script></p>');

        $this->assertStringContainsString('e2e', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    #[DataProvider('sanitizers')]
    public function test_整えても_storage_に記録を作らない(string $class): void
    {
        (new $class)->clean('<p>e2e<strong>x</strong></p>');

        $this->assertDirectoryDoesNotExist($this->storage.'/app/purifier');
    }

    private function configOf(object $sanitizer): HTMLPurifier_Config
    {
        $purifier = (fn (): HTMLPurifier => $this->instance())->call($sanitizer);

        return $purifier->config;
    }
}
