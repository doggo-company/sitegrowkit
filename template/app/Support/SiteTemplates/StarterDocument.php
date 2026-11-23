<?php

declare(strict_types=1);

namespace App\Support\SiteTemplates;

/** A single, editable fictional bakery. No claims about the user's business. */
final class StarterDocument
{
    /** @param array{hero: int, 1: int, 2: int} $media
     * @return list<array<string, mixed>>
     */
    public static function sections(array $media): array
    {
        $heading = static fn (string $text): array => ['type' => 'heading', 'props' => ['text' => $text, 'level' => 2]];
        $text = static fn (string $text): array => ['type' => 'text', 'props' => ['text' => $text]];
        $grid = static fn (array $children): array => ['type' => 'container', 'props' => ['layout' => 'grid', 'columns' => 2], 'children' => $children];
        $section = static fn (string $pattern, array $children, ?string $key = null): array => array_filter(['type' => 'section', 'key' => $key, 'props' => ['pattern' => $pattern], 'children' => $children], static fn ($value): bool => $value !== null);
        $stack = static fn (array $children): array => ['type' => 'container', 'props' => ['layout' => 'stack'], 'children' => $children];
        $image = static fn (int $id, string $alt): array => ['type' => 'image', 'props' => ['mediaId' => $id, 'alt' => $alt]];
        $contact = ['type' => 'button', 'props' => ['label' => 'お問い合わせ', 'href' => '/contact', 'variant' => 'primary']];

        return [
            ['type' => 'cover', 'props' => [
                'variant' => 'editorial', 'eyebrow' => 'パン屋 麦と日々',
                'mediaId' => $media['hero'], 'alt' => '窓辺に焼きたてのパンが並ぶ店内（生成した見本写真）',
                'caption' => 'パンが並ぶ窓辺。写真は生成した見本です。',
                'title' => "食パンの朝。\nクロワッサンの午後。",
                'lead' => "いつもの食卓に、散歩のおともに。\n暮らしに寄り添うパンを並べています。",
                'buttonLabel' => 'パンを見る', 'buttonHref' => '#bread-menu',
                'button2Label' => 'お問い合わせ', 'button2Href' => '/contact', 'level' => 1,
            ]],
            $section('menu', [
                $heading("朝の定番と、\nきょうの気分。"),
                ['type' => 'menutable', 'props' => ['caption' => '麦と日々のパン', 'items' => "山型食パン｜480円\nクロワッサン｜280円\nくるみとレーズン｜360円\n季節の果実のデニッシュ｜380円", 'note' => '価格は税込みです。品ぞろえは季節によって変わります。']],
            ], 'bread-menu'),
            $section('intro', [$grid([
                $image($media[2], '生地に粉を振る手元（生成した見本写真）'),
                $stack([$heading("手の仕事を、\n毎日の食卓へ。"), $text('特別な日だけでなく、なんでもない朝にも。香ばしい皮をかじる音や、ふわりと広がる小麦の香りを大切にしています。'."\n\n".'ひとりの朝ごはんにも、家族が集まる食卓にも。今日の気分に合うひとつを、ゆっくり見つけてください。')]),
            ])]),
            $section('photos', [$grid([
                $image($media[1], '食パンとクロワッサン（生成した見本写真）'),
                $stack([$heading("明日の朝は、\nどう食べよう。"), $text('そのまま味わう、こんがり焼く、好きな具をはさむ。パンの楽しみ方は、毎日の暮らしの中にあります。'."\n\n".'迷ったら、まずはいつものパンをひとつ。もうひとつは、まだ食べたことのないパンを選んでみませんか。')]),
            ])]),
            $section('contact', [$grid([
                $stack([$heading('パンのこと、お店のこと。'), $text('気になることがあれば、お問い合わせフォームからお寄せください。')]),
                $contact,
            ])]),
            $section('info', [$text('このページは、架空のパン屋「麦と日々」の見本です。店舗・商品・価格は実在の情報ではありません。写真は生成画像です。ご自身のお店の情報と写真に差し替えてから公開してください。')]),
        ];
    }
}
