<?php

namespace Database\Seeders;

use AgentCms\Core\Media\ImageProcessor;
use AgentCms\Core\Settings\SettingsStore;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Models\Media;
use Modules\News\Models\News;
use Modules\Works\Models\Work;

/**
 * デモサイト（5 ページ構成のコーポレートサイト）の初期コンテンツ。
 */
class DemoContentSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * デモ Works 全件に割り当てる sample visual の定義（Phase 8 F-7）。
     * run() で seed する Works と 1:1 に対応させること
     * （不足すると mainImage が null になり公開側で画像が欠ける）。
     *
     * @var list<array{slug: string, name: string, alt: string, rgb: array{int, int, int}}>
     */
    public const DEMO_MEDIA = [
        ['slug' => 'confectionery-ec', 'name' => '菓子ブランドEC メインビジュアル', 'alt' => '和菓子が並ぶオンラインストアのトップページ', 'rgb' => [214, 138, 108]],
        ['slug' => 'architecture-corporate', 'name' => '建築事務所サイト メインビジュアル', 'alt' => 'コンクリート建築のファサード写真', 'rgb' => [120, 144, 156]],
        ['slug' => 'cafe-branding', 'name' => 'カフェブランディング キービジュアル', 'alt' => 'コーヒーカップとロゴ入りスリーブ', 'rgb' => [141, 110, 99]],
        ['slug' => 'festival-keyvisual', 'name' => '音楽フェス キービジュアル', 'alt' => '夜空を背景にしたフェスティバルのキービジュアル', 'rgb' => [104, 96, 158]],
    ];

    public function run(): void
    {
        // サイト設定の既定は **どの site にも要る**ので、例の module の有無に関わらず入れる
        $this->seedMissingSettings();

        /*
         * ここから下は **kit の例のデモ記事**である（Phase 9.7 / STAGE 20B・SPEC §C）。
         *
         * `news` / `works` は新しく生成するサイトへ配らないので、その環境では
         * module ごと存在しない。**存在しないものを seed しようとして落とさない。**
         * 生成直後のサイトは投稿 0 件で始まる — 他人の会社のデモ記事を配らない。
         */
        if (! class_exists(News::class) || ! class_exists(Work::class)) {
            return;
        }

        $news = [
            ['title' => 'コーポレートサイトを公開しました', 'slug' => 'site-launch', 'body' => '<p>Studio Sample のコーポレートサイトを公開しました。制作事例やお知らせを随時更新していきます。</p>', 'days' => 3],
            ['title' => '夏季休業のお知らせ', 'slug' => 'summer-holiday-2026', 'body' => '<p>8月11日から8月15日まで夏季休業とさせていただきます。期間中のお問い合わせへの返信は8月18日以降となります。</p>', 'days' => 10],
            ['title' => '制作実績を更新しました', 'slug' => 'works-update-2026-07', 'body' => '<p>最近の制作実績を3件追加しました。<a href="/works">実績一覧</a>からご覧いただけます。</p>', 'days' => 21],
        ];
        foreach ($news as $item) {
            News::query()->updateOrCreate(['slug' => $item['slug']], [
                'title' => $item['title'],
                'body' => $item['body'],
                'status' => 'published',
                'published_at' => now()->subDays($item['days']),
                'seo_title' => null,
                'seo_description' => null,
            ]);
        }

        $works = [
            ['title' => '菓子ブランド ECサイト構築', 'slug' => 'confectionery-ec', 'category' => 'web', 'excerpt' => '地方の老舗菓子店のオンラインショップを、ブランドの世界観を保ったまま構築。', 'body' => '<p>創業80年の菓子店のECサイトを構築しました。撮影ディレクションから商品ページの情報設計、決済導入までを一貫して担当しています。</p>', 'days' => 30],
            ['title' => '建築事務所 コーポレートサイト', 'slug' => 'architecture-corporate', 'category' => 'web', 'excerpt' => '作品ポートフォリオを軸にした建築設計事務所のサイトリニューアル。', 'body' => '<p>竣工写真を最大限に活かすレイアウトを設計し、CMS で事例を自走更新できる体制を整えました。</p>', 'days' => 75],
            ['title' => 'カフェ ブランドロゴ・パッケージ', 'slug' => 'cafe-branding', 'category' => 'branding', 'excerpt' => '自家焙煎カフェのロゴとテイクアウトパッケージ一式を制作。', 'body' => '<p>ロゴ・ショップカード・カップスリーブまで、店舗体験を通したブランド設計を行いました。</p>', 'days' => 120],
            ['title' => '音楽フェス キービジュアル', 'slug' => 'festival-keyvisual', 'category' => 'graphic', 'excerpt' => '野外音楽フェスティバルのキービジュアルとポスター展開。', 'body' => '<p>会場装飾・Web・SNS まで展開できるキービジュアルシステムをデザインしました。</p>', 'days' => 200],
        ];
        foreach ($works as $item) {
            Work::query()->updateOrCreate(['slug' => $item['slug']], [
                'title' => $item['title'],
                'category' => $item['category'],
                'excerpt' => $item['excerpt'],
                'body' => $item['body'],
                'completed_at' => now()->subDays($item['days'])->toDateString(),
                'status' => 'published',
                'published_at' => now()->subDays($item['days']),
                'main_image_id' => null,
                'seo_title' => null,
                'seo_description' => null,
            ]);
        }

        $this->seedDemoMedia();
    }

    /**
     * サイト設定の demo default を「値が未設定の項目だけ」投入する。
     *
     * `agentcms dev` は毎回この seeder を実行するため、利用者が Setup Wizard や
     * 管理画面の「サイト設定」で入れた値を上書きしてはいけない（Phase 8.5-C.2）。
     *
     * missing の判定は **key が無い / 値が null** のみ。
     * 空文字は「利用者が意図的に消した値」として保持する（`!$value` のような
     * falsey 判定は使わない）。判定は項目ごとに行い、全体を all-or-nothing にしない。
     */
    private function seedMissingSettings(): void
    {
        $demoDefaults = [
            'site_name' => 'Studio Sample',
            'tagline' => '小さなチームのための、確かなウェブ制作',
            'seo_description' => 'Studio Sample は Web サイト制作・ブランディング・グラフィックデザインを手がけるデザインスタジオです。',
            'contact_email' => 'admin@example.test',
            'tel' => '03-0000-0000',
            'address' => '東京都渋谷区0-0-0',
            'x_url' => 'https://x.com/example',
            'instagram_url' => 'https://www.instagram.com/example/',
        ];

        $settings = app(SettingsStore::class);
        $current = $settings->all();

        $missing = [];
        foreach ($demoDefaults as $key => $value) {
            if (! array_key_exists($key, $current) || $current[$key] === null) {
                $missing[$key] = $value;
            }
        }

        if ($missing !== []) {
            $settings->put($missing);
        }
    }

    /**
     * デモ用 Media を GD で生成して Works に添付する。
     * unit テスト時はスキップ（実ストレージを汚さない。Media の検証は MediaTest が Storage::fake で行う）。
     */
    private function seedDemoMedia(): void
    {
        if (app()->runningUnitTests() || ! function_exists('imagecreatetruecolor')) {
            return;
        }

        $disk = Storage::disk((string) config('agentcms.uploads.disk'));
        $processor = app(ImageProcessor::class);

        $demos = self::DEMO_MEDIA;

        foreach ($demos as $demo) {
            $media = Media::query()->firstWhere('name', $demo['name']);

            if ($media === null) {
                $filename = 'media/demo-'.$demo['slug'].'.jpg';
                $image = $this->renderDemoImage($demo['rgb']);
                ob_start();
                imagejpeg($image, null, 82);
                $disk->put($filename, (string) ob_get_clean());
                imagedestroy($image);

                $hasWebp = $processor->makeWebp($disk->path($filename));

                $media = Media::query()->create([
                    'name' => $demo['name'],
                    'alt' => $demo['alt'],
                    'path' => $filename,
                    'mime' => 'image/jpeg',
                    'size' => (int) $disk->size($filename),
                    'width' => 1200,
                    'height' => 900,
                    'has_webp' => $hasWebp,
                ]);
            }

            Work::query()->where('slug', $demo['slug'])
                ->whereNull('main_image_id')
                ->update(['main_image_id' => $media->id]);
        }
    }

    /**
     * デモ用の抽象ビジュアルを GD で生成する（1200×900）。
     * 単色 + 楕円だけでは「未完成の placeholder」に見えるため（Phase 8 F-5C）、
     * 縦グラデーション + 半透明の円 + 対角の帯で「意図されたサンプル画像」にする。
     * 外部アセット・第三者ライセンスは使わない（自前生成のみ）。
     *
     * @param  array{int, int, int}  $rgb
     */
    private function renderDemoImage(array $rgb): \GdImage
    {
        [$r, $g, $b] = $rgb;
        $width = 1200;
        $height = 900;
        $image = imagecreatetruecolor($width, $height);

        // 縦グラデーション（基調色 → 明るい同系色）
        for ($y = 0; $y < $height; $y++) {
            $t = $y / $height;
            $color = (int) imagecolorallocate(
                $image,
                $this->channel($r + (255 - $r) * 0.35 * $t),
                $this->channel($g + (255 - $g) * 0.35 * $t),
                $this->channel($b + (255 - $b) * 0.35 * $t),
            );
            imageline($image, 0, $y, $width, $y, $color);
        }

        // 半透明の円を重ねる（奥行き）
        $lightA = (int) imagecolorallocatealpha($image, $this->channel($r + 55), $this->channel($g + 55), $this->channel($b + 55), 70);
        $lightB = (int) imagecolorallocatealpha($image, $this->channel($r + 90), $this->channel($g + 90), $this->channel($b + 90), 85);
        imagefilledellipse($image, (int) ($width * 0.68), (int) ($height * 0.38), 640, 640, $lightA);
        imagefilledellipse($image, (int) ($width * 0.25), (int) ($height * 0.78), 460, 460, $lightB);

        // 対角の帯（動きを出す）
        $dark = (int) imagecolorallocatealpha($image, $this->channel($r - 45), $this->channel($g - 45), $this->channel($b - 45), 88);
        imagefilledpolygon($image, [
            0, (int) ($height * 0.72),
            $width, (int) ($height * 0.30),
            $width, (int) ($height * 0.52),
            0, (int) ($height * 0.94),
        ], $dark);

        return $image;
    }

    /**
     * GD の色チャネル値へ丸める（本文で 0–255 に clamp している）。
     *
     * @return int<0, 255>
     */
    private function channel(float|int $value): int
    {
        $int = (int) $value;

        return max(0, min(255, $int));
    }
}
