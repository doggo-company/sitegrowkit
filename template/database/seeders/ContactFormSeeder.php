<?php

namespace Database\Seeders;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\FieldGroups\FieldGroupLocationKind;
use AgentCms\Core\FieldGroups\FieldGroupRepository;
use AgentCms\Core\FieldGroups\FieldGroupWriter;
use AgentCms\Core\Forms\Form;
use AgentCms\Core\Forms\FormStatus;
use AgentCms\Core\Schema\SchemaRegistry;
use App\Support\ContactEntry;
use Illuminate\Database\Seeder;

/**
 * `/contact` の受付フォームを 1 本入れる（R-12 / 受付の一本化）。
 *
 * ## なぜ要るか
 *
 * `ContactEntry::usesForm()` は「識別子 `contact` の公開中フォームが在るか」で
 * `/contact` の中身を決める。**生成直後はフォームが 0 本**なので、いままでは
 * 旧お問い合わせのままだった。その結果、管理画面の「受付」に
 *
 * - **フォーム** … 0 件（空）
 * - **お問い合わせ（従来）** … こちらが実際に動いている窓口
 *
 * の 2 つが並び、**新しそうな名前のほうが空**という読み違えやすい状態になっていた
 * （2026-08-30・オーナー指摘「二つに分ける意味がないでしょ」）。
 * **1 本入れれば `usesForm()` が true になり、旧側は出なくなる。**
 *
 * ## 冪等
 *
 * 既に `contact` のフォームが在れば**何もしない**。名前も項目も触らない —
 * 利用者が組み替えたものを seeder が上書きしてよい理由が無い
 * （`MenuSeeder` と同じ作法）。`agentcms dev` は既存の案件でも毎回ここを回すので、
 * **アップデートしただけで**フォームが用意される。
 *
 * ## 項目は schema から来る（写さない）
 *
 * 5 つの項目は `content/schemas/contact.json` が正本である。ここへ書き写すと、
 * **旧受信の移行**（`agentcms:contact-migrate` は同じ schema の `label` を使う）と
 * 食い違う。
 *
 * ▷ **例外は `subject` の選択肢だけ。** schema の `estimate` / `consultation` / `other` は
 * **旧テーブルの固定カラムに入っていた語彙**であって、人に見せる言葉ではない。
 * フォームの選択肢はそのまま画面に出る（`$field->choices`）ので、ここでだけ
 * 表示用の言葉へ置き換える。**旧受信を移行しても元の値は書き換わらない**
 * （移行は「問い合わせ主が書いたもの」をそのまま運ぶ）。
 */
class ContactFormSeeder extends Seeder
{
    /**
     * `subject` の表示用の選択肢。
     *
     * 旧側の自動返信（`modules/Contact/views/mail/auto-reply.blade.php`）が
     * 同じ対応を持っている。**あちらは旧受信を読むためのもの**なので畳めない。
     */
    private const SUBJECT_CHOICES = ['お見積もり', 'ご相談', 'その他'];

    public function run(): void
    {
        $key = ContactEntry::RESERVED_KEY;

        // 既に在るなら触らない（利用者が育てたものを上書きしない）
        if (Form::query()->where('key', $key)->exists()) {
            return;
        }

        $schemas = app(SchemaRegistry::class);

        if (! $schemas->has($key)) {
            return;
        }

        $groups = app(FieldGroupRepository::class);

        // 束が使えない構成では、項目の置き場所が無いので作らない
        if (! $groups->isInstalled()) {
            return;
        }

        $fields = [];

        foreach ($schemas->get($key)->fields as $field) {
            /*
             * **`toFormSpec()` をそのまま入れない。** あれは画面へ渡す形で `storage` を含み、
             * `FieldShape::DB_FORBIDDEN_KEYS` が DB の宣言では禁じている
             * （カラム化は migration を伴う opt-in なので、DB 側から選ばせない）。
             * ここでは**束が持ってよいキーだけ**を組む。
             */
            $shape = [
                'key' => $field->key,
                'type' => $field->type,
                'label' => $field->label,
            ];

            if ($field->required) {
                $shape['required'] = true;
            }

            if ($field->max !== null) {
                $shape['max'] = $field->max;
            }

            if ($field->choices !== []) {
                $shape['choices'] = $field->key === 'subject'
                    ? self::SUBJECT_CHOICES
                    : $field->choices;
            }

            $fields[] = $shape;
        }

        $context = OperationContext::cli('seeder:contact-form');

        $groupId = app(FieldGroupWriter::class)->create(
            $key.'_form',
            'お問い合わせ',
            $fields,
            [['kind' => FieldGroupLocationKind::Form->value, 'value' => $key]],
            $context,
        );

        $form = new Form;
        $form->forceFill([
            'key' => $key,
            'name' => 'お問い合わせ',
            'description' => '制作のご相談・お見積もりは下記フォームからお送りください。',
            'field_group_id' => $groupId,
            'status' => FormStatus::Published->value,
            /*
             * **確認画面は挟まない。** 旧お問い合わせが挟んでいなかったので、
             * 一本化しただけで手順が 1 つ増えると「変わった」と読まれる。
             * 挟みたい案件は管理画面で切り替えられる。
             */
            'confirm_step' => false,
            'submit_label' => '送信',
            /*
             * **自動返信の宛先は推測させない**（SPEC §E.3 / OG6）。
             * ここを空にすると自動返信そのものが送られない — 旧側は送っていたので、
             * 一本化で**黙って止まる**ことになる。
             */
            'autoreply_field' => 'email',
            'lock_version' => 0,
        ]);
        $form->save();

        /*
         * 通知先（`notify_to`）は**空のまま**にする。
         * 空ならサイト設定の連絡先 → `agentcms.mail.admin_to` の順に落ちる
         * （`PublicFormController`）。旧側と同じ落ち方である。
         */
    }
}
