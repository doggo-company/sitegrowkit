<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Media\Models\Media;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

final class Round3ImageAltTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    public static function descriptions(): array
    {
        return [[null, '庭で遊ぶ犬', '庭で遊ぶ犬'], ['', '庭で遊ぶ犬', '庭で遊ぶ犬'], ['もとの説明', '庭で遊ぶ犬', 'もとの説明'], [null, '', null]];
    }

    #[DataProvider('descriptions')]
    public function test_page_publish_fills_only_empty_media_description(?string $before, string $block, ?string $expected): void
    {
        $media = new Media;
        $media->forceFill(['name' => '犬', 'original_name' => 'dog.jpg', 'path' => 'dog.jpg', 'mime' => 'image/jpeg', 'size' => 20, 'alt' => $before])->save();
        $context = OperationContext::admin($this->operatorId(), 'tester');
        $created = app(CreatePage::class)->execute(['title' => '写真', 'slug' => 'photo'], $context);
        $this->assertTrue($created->isSuccess());
        $section = app(PatchPageDocument::class)->execute($created->data['page']['id'], [
            'operation' => 'component.insert', 'parent' => 'page', 'component' => ['type' => 'section', 'props' => []],
        ], $context);
        $this->assertTrue($section->isSuccess());
        $result = app(PatchPageDocument::class)->execute($created->data['page']['id'], [
            'operation' => 'component.insert', 'parent' => $section->data['document']['root']['children'][0]['id'],
            'component' => ['type' => 'image', 'props' => ['mediaId' => $media->id, 'alt' => $block]],
        ], $context);
        $this->assertTrue($result->isSuccess(), (string) json_encode($result->errors));
        // 下書きの保存（打つ途中の自動保存も同じ道）では、まだメディアへ写さない
        $this->assertSame($before, $media->fresh()->alt);
        $published = app(PublishPage::class)->execute($created->data['page']['id'], $context);
        $this->assertTrue($published->isSuccess(), (string) json_encode($published->errors));
        $this->assertSame($expected, $media->fresh()->alt);
        $this->assertSame($expected, $media->fresh()->toPickerArray()['alt']);
    }
}
