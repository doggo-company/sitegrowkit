<?php

declare(strict_types=1);

namespace App\Support\Industry;

/**
 * 業種ごとの「最初から、その店専用の形」（UX 一周 2026-09-28・メンテナの方針）。
 *
 * SiteGrowKit は「究極のパーソナライズ CMS」。汎用の WordPress の逆で、
 * **その店・その業種の形で最初から出てくる**ことを目指す。ここはその初期値の正本で、
 * 最初に業種を選んだとき（ダッシュボードの入口）にだけ使う。
 *
 * 🔴 **見本の文は、事実を断言しない**（利用者に見せる文はファクトチェックのルール）。
 * 「〜です」「〜できます」と店のことを言い切る文は置かない。**何を書く場所か**を案内する文と、
 * 空欄（〇〇）の入った書き方の例だけにする。店の事実は、店の人が自分で書く。
 *
 * 対象は、UX 一周で使った 5 業種、教室・スクール、医院・整骨院・サロン、士業の事務所、お花・雑貨などのお店、「その他」の 10 種。
 * 教室と医院・整骨院・サロンは UX 一周 3 周目（M2）で足した。ピアノ教室と整骨院が
 * 「その他」を選ぶしかなく、「会社名・屋号」「ご相談／その他」のままになっていた。
 *
 * `companyLabel` は、お問い合わせの「会社名」の欄の名前（`keepCompany` が true のときだけ使う）。
 * 「その他」は会社とは限らないので「お店・屋号の名前」にする（UX 一周 3 周目 M2）。
 */
final class IndustryProfiles
{
    public const SETTING_KEY = 'industry';

    /**
     * @return array<string, array{
     *     label: string,
     *     postLabel: string,
     *     listSlug: string,
     *     contactDescription: string,
     *     subjectLabel: string,
     *     subjectChoices: list<string>,
     *     extraFields: list<array{key: string, type: string, label: string, choices?: list<string>}>,
     *     keepCompany: bool,
     *     companyLabel?: string,
     *     serviceNoun: string,
     *     ctaLabel: string,
     *     heroTitle: string,
     *     aboutHints: list<string>,
     *     serviceRows: list<array{0: string, 1: string}>
     * }>
     */
    public static function all(): array
    {
        return [
            'trimming' => [
                'label' => 'トリミングサロン・ペットのお店',
                'postLabel' => 'お知らせ',
                // 記事の一覧の URL（/news）。使えない（ほかと重なる）ときは今の URL のまま
                'listSlug' => 'news',
                'contactDescription' => 'ご予約・ご相談はこちらからどうぞ。',
                'subjectLabel' => 'ご用件',
                'subjectChoices' => ['ご予約', 'トリミングのご相談', 'その他'],
                'extraFields' => [
                    ['key' => 'pet_name', 'type' => 'text', 'label' => 'ワンちゃん・ネコちゃんのお名前'],
                    ['key' => 'breed', 'type' => 'text', 'label' => '犬種・猫種'],
                    ['key' => 'preferred_date', 'type' => 'text', 'label' => 'ご希望の日時'],
                ],
                'keepCompany' => false,
                'serviceNoun' => 'メニュー',
                // 見本トップの 1 つ目のボタン（問い合わせへ）。店のことは言い切らない
                'ctaLabel' => '予約・相談する',
                // 見本トップの見出し（キャッチコピー未入力のとき）。〇〇の空欄だけで、店のことを言い切らない
                'heroTitle' => '〇〇（地域）のトリミングサロン',
                'aboutHints' => ['お店を始めたきっかけ', '施術のときに大切にしていること', 'スタッフの紹介'],
                'serviceRows' => [['シャンプーコース（〇〇サイズ）', '〇〇円〜'], ['カットコース（〇〇サイズ）', '〇〇円〜'], ['爪切りなどの単品', '〇〇円']],
            ],
            'koumuten' => [
                'label' => '工務店・リフォーム',
                'postLabel' => '施工事例',
                // 記事の一覧の URL（/works）。使えない（ほかと重なる）ときは今の URL のまま
                'listSlug' => 'works',
                'contactDescription' => '新築・リフォームのご相談はこちらからどうぞ。',
                'subjectLabel' => 'ご相談の内容',
                'subjectChoices' => ['新築のご相談', 'リフォームのご相談', '資料のご請求', 'その他'],
                'extraFields' => [
                    ['key' => 'site_area', 'type' => 'text', 'label' => '建築・工事の予定地（市区町村）'],
                ],
                'keepCompany' => false,
                'serviceNoun' => '家づくりの流れ',
                // 見本トップの 1 つ目のボタン（問い合わせへ）。店のことは言い切らない
                'ctaLabel' => '家づくりを相談する',
                // 見本トップの見出し（キャッチコピー未入力のとき）。〇〇の空欄だけで、店のことを言い切らない
                'heroTitle' => '〇〇（地域）の工務店',
                'aboutHints' => ['会社の歩み', '家づくりで大切にしていること', '対応している地域'],
                'serviceRows' => [['ご相談・現地調査', '〇〇'], ['プランとお見積もり', '〇〇'], ['着工からお引き渡しまで', '〇〇']],
            ],
            'zeirishi' => [
                'label' => '税理士・会計事務所',
                'postLabel' => 'お知らせ・コラム',
                // 記事の一覧の URL（/column）。使えない（ほかと重なる）ときは今の URL のまま
                'listSlug' => 'column',
                'contactDescription' => 'ご相談の内容を、わかる範囲でお書きください。',
                'subjectLabel' => 'ご相談の種類',
                'subjectChoices' => ['記帳・会計', '確定申告', '創業のご相談', 'その他'],
                'extraFields' => [
                    ['key' => 'business_type', 'type' => 'select', 'label' => '事業の形態', 'choices' => ['個人事業', '法人', 'これから開業', 'その他']],
                ],
                'keepCompany' => true,
                'serviceNoun' => '業務内容と料金',
                // 見本トップの 1 つ目のボタン（問い合わせへ）。店のことは言い切らない
                'ctaLabel' => '相談を申し込む',
                // 見本トップの見出し（キャッチコピー未入力のとき）。〇〇の空欄だけで、店のことを言い切らない
                'heroTitle' => '〇〇（地域）の〇〇事務所',
                'aboutHints' => ['事務所の紹介', '代表のあいさつ', 'ご相談の進め方'],
                'serviceRows' => [['〇〇のご相談', '月額 〇〇円〜'], ['〇〇の代行', '〇〇円〜'], ['初回のご相談', '〇〇']],
            ],
            'shigyo' => [
                // 司法書士・行政書士・社労士など（UX 一周 7 周目 R7-5: 「士業」を選ぶと相談の種類が税理士の言葉だった）。
                // 業務の名前は事務所ごとに違うので、相談の種類は業務を名指ししない
                'label' => '司法書士・行政書士など士業の事務所',
                'postLabel' => 'お知らせ・コラム',
                // 記事の一覧の URL（/column）。使えない（ほかと重なる）ときは今の URL のまま
                'listSlug' => 'column',
                'contactDescription' => 'ご相談の内容を、わかる範囲でお書きください。',
                'subjectLabel' => 'ご用件',
                'subjectChoices' => ['ご相談のご予約', '費用のお問い合わせ', 'その他'],
                'extraFields' => [
                    ['key' => 'preferred_date', 'type' => 'text', 'label' => 'ご希望の日時'],
                ],
                'keepCompany' => false,
                'serviceNoun' => '業務内容と費用',
                // 見本トップの 1 つ目のボタン（問い合わせへ）。店のことは言い切らない
                'ctaLabel' => '相談を申し込む',
                // 見本トップの見出し（キャッチコピー未入力のとき）。〇〇の空欄だけで、店のことを言い切らない
                'heroTitle' => '〇〇（地域）の〇〇事務所',
                'aboutHints' => ['事務所の紹介', '代表のあいさつ', 'ご相談の進め方'],
                'serviceRows' => [['〇〇のご相談', '〇〇円〜'], ['〇〇の手続き', '〇〇円〜'], ['初回のご相談', '〇〇']],
            ],
            'cafe' => [
                'label' => 'カフェ・飲食店',
                // 「メニュー」は管理画面ではサイトのナビの画面の名前。料理のメニューはメニュー表のブロックで
                // ページに置く（UX 一周 第12周: サイドバーに「お知らせ・メニュー」と「メニュー」が並んで迷った）
                'postLabel' => 'お知らせ',
                // 記事の一覧の URL（/news）。使えない（ほかと重なる）ときは今の URL のまま
                'listSlug' => 'news',
                'contactDescription' => 'ご予約・お問い合わせはこちらからどうぞ。',
                'subjectLabel' => 'ご用件',
                'subjectChoices' => ['席のご予約', '貸し切りのご相談', 'その他'],
                'extraFields' => [
                    ['key' => 'party_size', 'type' => 'text', 'label' => '人数'],
                    ['key' => 'preferred_date', 'type' => 'text', 'label' => 'ご希望の日時'],
                ],
                'keepCompany' => false,
                'serviceNoun' => 'メニュー',
                // 見本トップの 1 つ目のボタン（問い合わせへ）。店のことは言い切らない
                'ctaLabel' => '予約・問い合わせる',
                // 見本トップの見出し（キャッチコピー未入力のとき）。〇〇の空欄だけで、店のことを言い切らない
                'heroTitle' => '〇〇（地域）のカフェ',
                'aboutHints' => ['お店のこと', 'こだわっていること', '営業時間と定休日'],
                'serviceRows' => [['〇〇（ドリンク）', '〇〇円'], ['〇〇（フード）', '〇〇円'], ['〇〇（デザート）', '〇〇円']],
            ],
            'shop' => [
                // 花屋・雑貨などの小売（UX 一周 8 周目 M2: 花屋が「作家・ものづくり」か「その他」しか選べなかった）
                'label' => 'お花・雑貨などのお店',
                'postLabel' => 'お知らせ',
                // 記事の一覧の URL（/news）。使えない（ほかと重なる）ときは今の URL のまま
                'listSlug' => 'news',
                'contactDescription' => 'ご注文・ご相談はこちらからどうぞ。',
                'subjectLabel' => 'ご用件',
                'subjectChoices' => ['ご注文のご相談', '商品について', 'その他'],
                'extraFields' => [
                    ['key' => 'preferred_date', 'type' => 'text', 'label' => 'ご希望の日（お受け取り・お届け）'],
                ],
                'keepCompany' => false,
                'serviceNoun' => '商品と料金',
                // 見本トップの 1 つ目のボタン（問い合わせへ）。店のことは言い切らない
                'ctaLabel' => '注文・相談する',
                // 見本トップの見出し（キャッチコピー未入力のとき）。〇〇の空欄だけで、店のことを言い切らない
                'heroTitle' => '〇〇（地域）の〇〇',
                'aboutHints' => ['お店の紹介', '扱っている商品', '営業時間と定休日'],
                'serviceRows' => [['〇〇（商品名）', '〇〇円〜'], ['〇〇（商品名）', '〇〇円〜'], ['お届け・配達', '〇〇']],
            ],
            'sakka' => [
                'label' => '作家・ものづくり',
                'postLabel' => '作品・展示のお知らせ',
                // 記事の一覧の URL（/exhibitions）。使えない（ほかと重なる）ときは今の URL のまま
                'listSlug' => 'exhibitions',
                'contactDescription' => '作品や展示についてのお問い合わせはこちらからどうぞ。',
                'subjectLabel' => 'ご用件',
                'subjectChoices' => ['作品について', '展示・イベントについて', 'お取り扱いのご相談', 'その他'],
                'extraFields' => [],
                'keepCompany' => false,
                'serviceNoun' => '作品',
                // 見本トップの 1 つ目のボタン（問い合わせへ）。店のことは言い切らない
                'ctaLabel' => '問い合わせる',
                // 見本トップの見出し（キャッチコピー未入力のとき）。〇〇の空欄だけで、店のことを言い切らない
                'heroTitle' => '〇〇（素材や作品）をつくる〇〇',
                'aboutHints' => ['つくり手の紹介', '作品づくりで大切にしていること', 'これまでの展示'],
                'serviceRows' => [['〇〇（作品の種類）', '〇〇円〜'], ['〇〇（作品の種類）', '〇〇円〜'], ['オーダーのご相談', '〇〇']],
            ],
            'school' => [
                'label' => '教室・スクール',
                'postLabel' => 'お知らせ',
                // 記事の一覧の URL（/news）。使えない（ほかと重なる）ときは今の URL のまま
                'listSlug' => 'news',
                'contactDescription' => '入会や体験のご相談はこちらからどうぞ。',
                'subjectLabel' => 'ご用件',
                'subjectChoices' => ['入会のご相談', '体験のご相談', 'その他'],
                'extraFields' => [
                    ['key' => 'student_age', 'type' => 'text', 'label' => '習う方の年齢・学年'],
                    ['key' => 'preferred_date', 'type' => 'text', 'label' => 'ご希望の日時'],
                ],
                'keepCompany' => false,
                'serviceNoun' => 'コースと料金',
                // 見本トップの 1 つ目のボタン（問い合わせへ）。店のことは言い切らない
                'ctaLabel' => '相談・申し込む',
                // 見本トップの見出し（キャッチコピー未入力のとき）。〇〇の空欄だけで、店のことを言い切らない
                'heroTitle' => '〇〇（地域）の〇〇教室',
                'aboutHints' => ['教室の紹介', '講師の紹介', 'レッスンの日時と場所'],
                'serviceRows' => [['〇〇コース（〇〇向け）', '月謝 〇〇円'], ['〇〇コース（〇〇向け）', '月謝 〇〇円'], ['入会金・教材費', '〇〇円']],
            ],
            'clinic' => [
                'label' => '医院・整骨院・サロン',
                'postLabel' => 'お知らせ',
                // 記事の一覧の URL（/news）。使えない（ほかと重なる）ときは今の URL のまま
                'listSlug' => 'news',
                'contactDescription' => 'ご予約・ご相談はこちらからどうぞ。',
                'subjectLabel' => 'ご用件',
                'subjectChoices' => ['初めての方のご予約', '2回目以降のご予約', 'ご相談', 'その他'],
                'extraFields' => [
                    ['key' => 'preferred_date', 'type' => 'text', 'label' => 'ご希望の日時'],
                ],
                'keepCompany' => false,
                'serviceNoun' => 'メニューと料金',
                // 見本トップの 1 つ目のボタン（問い合わせへ）。効き目・治る等は言わない（店のことは言い切らない）
                'ctaLabel' => '予約・相談する',
                // 見本トップの見出し（キャッチコピー未入力のとき）。〇〇の空欄だけで、店のことを言い切らない
                'heroTitle' => '〇〇（地域）の〇〇',
                'aboutHints' => ['院・お店の紹介', 'スタッフの紹介', '受付時間と休みの日'],
                'serviceRows' => [['〇〇（メニューの名前）', '〇〇円'], ['〇〇（メニューの名前）', '〇〇円'], ['初めての方', '〇〇']],
            ],
            /*
             * 「その他」は、お仕事の名前を自分で打つ（UX 一周 9 周目 M2・メンテナの決定）。
             * 業種は増やさない。人物が替わるたびに「合う業種が無い」が出続けたため（宿・民宿など）。
             * `{name}` は打った名前に置き換わる（`IndustryProfiles::resolve()`）。名前が空なら「〇〇」。
             * 項目は業種に寄らない形（会社名の欄は置かない・ご希望の日時は置く）。店のことは言い切らない
             */
            'other' => [
                'label' => 'その他',
                'postLabel' => 'お知らせ',
                // 記事の一覧の URL（/news）。使えない（ほかと重なる）ときは今の URL のまま
                'listSlug' => 'news',
                'contactDescription' => '{name}へのご予約・ご相談はこちらからどうぞ。',
                'subjectLabel' => 'ご用件',
                'subjectChoices' => ['ご予約・お申し込み', 'ご相談', 'その他'],
                'extraFields' => [
                    ['key' => 'preferred_date', 'type' => 'text', 'label' => 'ご希望の日時'],
                ],
                'keepCompany' => false,
                'serviceNoun' => 'サービス',
                // 見本トップの 1 つ目のボタン（問い合わせへ）。店のことは言い切らない
                'ctaLabel' => '問い合わせる',
                // 見本トップの見出し（キャッチコピー未入力のとき）。〇〇の空欄だけで、店のことを言い切らない
                'heroTitle' => '〇〇（地域）の{name}',
                'aboutHints' => ['{name}の紹介', '大切にしていること', 'これまでの歩み'],
                'serviceRows' => [['〇〇', '〇〇円〜'], ['〇〇', '〇〇円〜'], ['〇〇', '〇〇']],
            ],
        ];
    }

    /** 「その他」で打ったお仕事の名前の設定キー（UX 一周 9 周目 M2） */
    public const NAME_KEY = 'industry_name';

    /** 名前に使える長さ（画面の欄と同じ） */
    public const NAME_MAX = 40;

    /**
     * 前の版の「その他」のお問い合わせの形。これのままなら「手を入れていない」とみなして新しい形へ替える
     *
     * @return list<array<string, mixed>>
     */
    public static function legacyOtherShapes(): array
    {
        $base = ['contactDescription' => 'お問い合わせはこちらからどうぞ。', 'subjectLabel' => 'ご用件', 'subjectChoices' => ['ご相談', 'その他'], 'extraFields' => [], 'keepCompany' => true];

        return [
            [...$base, 'companyLabel' => 'お店・屋号の名前'],
            [...$base, 'companyLabel' => '会社名・屋号'],
        ];
    }

    /**
     * `{name}` を打った名前に置き換える。名前が空なら「〇〇」、ただし「〇〇へのご予約」のような
     * 見本の言い回しにならないよう、問い合わせの説明だけは名前を外した文にする。
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function resolve(array $profile, ?string $name): array
    {
        $name = trim((string) $name);

        if ($name === '' && is_string($profile['contactDescription'] ?? null)) {
            $profile['contactDescription'] = str_replace('{name}へのご予約・ご相談', 'ご予約・ご相談', $profile['contactDescription']);
        }

        $walk = static function (mixed $value) use (&$walk, $name): mixed {
            if (is_string($value)) {
                return str_replace('{name}', $name !== '' ? $name : '〇〇', $value);
            }

            return is_array($value) ? array_map($walk, $value) : $value;
        };

        $resolved = $walk($profile);

        if ($name !== '' && ($profile['label'] ?? null) === 'その他') {
            $resolved['label'] = $name;
        }

        return $resolved;
    }

    /** @return array<string, mixed>|null */
    public static function get(?string $key): ?array
    {
        return $key !== null ? (self::all()[$key] ?? null) : null;
    }

    /** 業種を選ぶ前のサービスのページの呼び名（`DefaultNavigation` / 見本の一覧がこれで始まる） */
    public const DEFAULT_SERVICE_NOUN = 'サービス';

    /**
     * サービスのページ（/service）の呼び名。業種を選んでいれば店の呼び名（カフェなら「メニュー」）。
     *
     * ナビ・フッター・ページの一覧・メニューの画面・リンク先の候補は、全部これで言う
     * （UX 一周 第 15 周: /service の題だけ「メニュー」で、ほかは「サービス」のままだった）。
     */
    public static function serviceNoun(?string $key): string
    {
        $profile = self::get($key);

        return is_string($profile['serviceNoun'] ?? null) && $profile['serviceNoun'] !== ''
            ? $profile['serviceNoun']
            : self::DEFAULT_SERVICE_NOUN;
    }

    /**
     * 業種が付ける呼び名の全部（既定の「サービス」を含む）。
     * この中のどれかなら「店の人が自分で付けた名前ではない」ので、選び直しで替えてよい。
     *
     * @return list<string>
     */
    public static function serviceNouns(): array
    {
        return array_values(array_unique([self::DEFAULT_SERVICE_NOUN, ...array_column(self::all(), 'serviceNoun')]));
    }

    /**
     * 選ぶ前に、何がどう替わるかを 1 行で言う（UX 一周 12 周目 R12-1: 猫カフェに「カフェ・飲食店」と
     * 「ペットのお店」の両方が当てはまり、押すまで違いが見えなかった）。お問い合わせの項目と記事の呼び名だけ
     *
     * @return list<array{key: string, label: string, hint: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::all() as $key => $profile) {
            $hint = $key === 'other'
                ? 'お仕事の名前を自分で打ちます'
                : 'ご用件: '.implode('／', array_slice($profile['subjectChoices'], 0, 3))
                    .($profile['extraFields'] !== [] ? '・'.implode('・', array_column($profile['extraFields'], 'label')) : '')
                    .'／記事: '.$profile['postLabel'];
            $options[] = ['key' => $key, 'label' => $profile['label'], 'hint' => $hint];
        }

        return $options;
    }
}
