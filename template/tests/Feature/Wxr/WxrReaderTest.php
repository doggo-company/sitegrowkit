<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\WxrError;
use AgentCms\Core\Wxr\WxrItem;
use AgentCms\Core\Wxr\WxrReader;
use AgentCms\Core\Wxr\WxrSummary;
use AgentCms\Core\Wxr\WxrTermDefinition;
use Tests\TestCase;

/**
 * WXR の読み取り（Phase 9.14 / STAGE 27B・SPEC §B・§F）。
 *
 * ここで固定するのは **外部実体を通さないこと**と、
 * **壊れたデータで黙って進まないこと**、そして **DB を 1 行も読まないこと**である。
 */
final class WxrReaderTest extends TestCase
{
    private string $dir;

    private string $originalTimezone = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTimezone = date_default_timezone_get();

        $this->dir = sys_get_temp_dir().'/wxr-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);

        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    // ── XXE と展開爆弾（§B.3・受入条件 1） ────────────────────

    public function test_外部実体を含むwxrを取り込まない(): void
    {
        $secret = $this->dir.'/secret.txt';
        file_put_contents($secret, 'APP_KEY=base64:dont-read-me');

        $path = $this->write('xxe.xml', <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE rss [<!ENTITY xxe SYSTEM "file://{$secret}">]>
            <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
            <channel><item><title>&xxe;</title><wp:post_id>1</wp:post_id></item></channel>
            </rss>
            XML);

        $error = $this->errorFrom($path);

        // 読めてしまう前に、DOCTYPE の時点で断る
        $this->assertStringContainsString('DOCTYPE', $error->getMessage());
    }

    public function test_外部実体の中身が漏れていない(): void
    {
        $secret = $this->dir.'/secret.txt';
        file_put_contents($secret, 'APP_KEY=base64:dont-read-me');

        $path = $this->write('xxe.xml', <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE rss [<!ENTITY xxe SYSTEM "file://{$secret}">]>
            <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
            <channel><item><title>&xxe;</title><wp:post_id>1</wp:post_id></item></channel>
            </rss>
            XML);

        $titles = [];

        try {
            foreach ((new WxrReader($path))->items() as $item) {
                $titles[] = $item->title;
            }
        } catch (WxrError) {
            // 断られるのが正しい
        }

        $this->assertStringNotContainsString('dont-read-me', implode('', $titles));
    }

    public function test_展開爆弾を取り込まない(): void
    {
        // billion laughs。DOCTYPE を断る時点で成立しない
        $path = $this->write('bomb.xml', <<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE lolz [
             <!ENTITY lol "lol">
             <!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">
            ]>
            <rss version="2.0"><channel><item><title>&lol2;</title></item></channel></rss>
            XML);

        $this->assertStringContainsString('DOCTYPE', $this->errorFrom($path)->getMessage());
    }

    // ── 壊れた入力（§F・受入条件 3） ──────────────────────────

    public function test_wxrに見えないファイルを断る(): void
    {
        $path = $this->write('not-wxr.txt', 'これはただのテキストです');

        $this->assertStringContainsString('WXR', $this->errorFrom($path)->getMessage());
    }

    public function test_空のファイルを断る(): void
    {
        $this->assertStringContainsString('空', $this->errorFrom($this->write('empty.xml', ''))->getMessage());
    }

    public function test_utf8で読めないバイト列を断る(): void
    {
        $broken = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            .'<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/"><channel>'
            ."<item><title>\xC3\x28壊れた</title><wp:post_id>1</wp:post_id></item>"
            .'</channel></rss>';

        $error = $this->errorFrom($this->write('broken.xml', $broken));

        /*
         * 黙って `?` へ置換しない（文字化けした記事が大量にできてから気づく、を作らない）。
         *
         * **libxml の文言では確かめない。** 版によって変わるので、
         * こちらが書いた説明文（利用者が読むもの）で確かめる。
         */
        $this->assertStringContainsString('UTF-8', $error->hint);
        $this->assertStringContainsString('気づけません', $error->hint);
    }

    public function test_post_idが重複していたら止まる(): void
    {
        $path = $this->write('dup.xml', $this->wxr(<<<'XML'
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type></item>
            <item><title>B</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type></item>
            XML));

        // **集計を通さずに読んでも**止まる（迂回できる道を作らない）
        $this->assertStringContainsString('2 回', $this->errorFrom($path)->getMessage());
    }

    public function test_重複の検査は集計に依存しない(): void
    {
        $path = $this->write('dup.xml', $this->wxr(<<<'XML'
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type></item>
            <item><title>B</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type></item>
            XML));

        $seen = 0;

        try {
            foreach ((new WxrReader($path))->items() as $ignored) {
                $seen++;
            }
            $this->fail('重複を素通りしました');
        } catch (WxrError) {
            // 1 件目は返り、2 件目で止まる
            $this->assertSame(1, $seen);
        }
    }

    // ── WXR かどうかの判定（レビュー指摘 1・2） ──────────────

    public function test_整形式なだけのxmlを成功にしない(): void
    {
        // `<rss>` も wp 名前空間も無い。**item 0 件の成功**にしてはいけない
        $path = $this->write('other.xml', '<?xml version="1.0"?><catalog><book id="1"/></catalog>');

        $this->assertStringContainsString('WXR', $this->errorFrom($path)->getMessage());
    }

    public function test_rssでもwp名前空間が無ければ断る(): void
    {
        $path = $this->write('plain-rss.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>ただの RSS</title>
            <item><title>記事</title></item></channel></rss>
            XML);

        $this->assertStringContainsString('WXR', $this->errorFrom($path)->getMessage());
    }

    // ── root と channel の構造（ディレクター指摘） ──────────────

    /*
     * ここまでの判定は「文書のどこかに <rss> と wp 名前空間があるか」しか見ていなかった。
     * それだと **WXR ではない文書が WXR として通る**。取り込んだつもりで別物が入る、
     * あるいは item を静かに取りこぼすのが、最も気づきにくい失敗である。
     *
     * 見るのは形そのもの — root が rss、その直下に channel、
     * wxr_version と item はその channel の直下。接頭辞の文字列には依存しない。
     */

    public function test_rssがrootでなければ断る(): void
    {
        // `<rss>` は文書のどこかにあればよいのではない。**root でなければならない**
        $path = $this->write('nested-rss.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <catalog><rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
            <channel><wp:wxr_version>1.2</wp:wxr_version>
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type></item>
            </channel></rss></catalog>
            XML);

        $error = $this->errorFrom($path);

        $this->assertStringContainsString('WXR', $error->getMessage());
        $this->assertStringContainsString('rss', $error->hint);
    }

    public function test_channelが無ければ断る(): void
    {
        // `<rss>` の直下に `<channel>` が無い文書は WXR ではない
        $path = $this->write('no-channel.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
            <wp:wxr_version>1.2</wp:wxr_version>
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type></item>
            </rss>
            XML);

        $this->assertStringContainsString('channel', $this->errorFrom($path)->hint);
    }

    public function test_wxr_versionがchannelの外なら断る(): void
    {
        // 版の宣言は channel の直下にある。外にある版を読んで「対応版だ」と決めない
        $path = $this->write('version-outside.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
            <wp:wxr_version>1.2</wp:wxr_version>
            <channel><title>移行元</title>
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type></item>
            </channel></rss>
            XML);

        $this->assertStringContainsString('channel', $this->errorFrom($path)->hint);
    }

    public function test_itemがchannelの外なら断る(): void
    {
        /*
         * **これは黙って 0 件になる形である。** channel の外の item を拾わない実装は
         * 「item 0 件の正しい WXR」として成功してしまう。取りこぼしを成功にしない。
         */
        $path = $this->write('item-outside.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
            <channel><title>移行元</title><wp:wxr_version>1.2</wp:wxr_version></channel>
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type></item>
            </rss>
            XML);

        $this->assertStringContainsString('channel', $this->errorFrom($path)->hint);
    }

    public function test_channelが2つある文書を断る(): void
    {
        // どちらの channel を取り込むか決められない。**黙って片方を採らない**
        $path = $this->write('two-channels.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
            <channel><wp:wxr_version>1.2</wp:wxr_version>
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type></item>
            </channel>
            <channel><wp:wxr_version>1.2</wp:wxr_version>
            <item><title>B</title><wp:post_id>2</wp:post_id><wp:post_type>post</wp:post_type></item>
            </channel></rss>
            XML);

        $this->assertStringContainsString('channel', $this->errorFrom($path)->hint);
    }

    // ── 名前空間の版と本文の版（ディレクター再レビュー） ────────

    public function test_名前空間の版と本文の版が食い違う文書を断る(): void
    {
        /*
         * **版は 2 か所で名乗られる。** 名前空間 URI（要素の集合を決める）と、
         * `wxr_version` の本文（書き出しが名乗る版）である。
         *
         * 前方一致で名前空間を受けて本文だけで版を決めると、この文書が
         * 「対応版 1.2」として通る。だが item の中の要素は 1.1 名前空間なので、
         * **取れなかった値が空のまま静かに入る**。読めない版は読めないと言う。
         */
        $path = $this->write('version-mismatch.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"
                xmlns:wp="http://wordpress.org/export/1.2/"
                xmlns:old="http://wordpress.org/export/1.1/">
            <channel><title>矛盾した書き出し</title>
            <old:wxr_version>1.2</old:wxr_version>
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type></item>
            </channel></rss>
            XML);

        $error = $this->errorFrom($path);

        // 接頭辞（old / wp）ではなく、名乗られた版どうしの食い違いを言う
        $this->assertStringContainsString('1.1', $error->getMessage());
        $this->assertStringContainsString('1.2', $error->getMessage());
    }

    public function test_wxr_versionが2つある文書を断る(): void
    {
        // どちらの版で読むか決められない。**黙って先に見つけた方を採らない**
        $path = $this->write('two-versions.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
            <channel><title>移行元</title>
            <wp:wxr_version>1.2</wp:wxr_version>
            <wp:wxr_version>1.2</wp:wxr_version>
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type></item>
            </channel></rss>
            XML);

        $this->assertStringContainsString('wxr_version', $this->errorFrom($path)->getMessage());
    }

    public function test_wxr_versionが無ければ断る(): void
    {
        // 名前空間はあるが版の宣言が無い
        $path = $this->write('no-version.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/"><channel>
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type></item>
            </channel></rss>
            XML);

        $this->assertStringContainsString('wxr_version', $this->errorFrom($path)->hint);
    }

    public function test_実物の1_1を未対応として断る(): void
    {
        /*
         * **実物の 1.1 は名前空間も `/export/1.1/` である。**
         * 1.2 の名前空間のまま版だけ 1.1 にした fixture では、
         * 「WXR に見えない」で先に落ちてしまい、版の判定を確かめられない。
         */
        $path = $this->write('wxr-1.1.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"
                xmlns:content="http://purl.org/rss/1.0/modules/content/"
                xmlns:excerpt="http://wordpress.org/export/1.1/excerpt/"
                xmlns:wp="http://wordpress.org/export/1.1/">
            <channel><title>古い書き出し</title>
            <wp:wxr_version>1.1</wp:wxr_version>
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type></item>
            </channel></rss>
            XML);

        $error = $this->errorFrom($path);

        // 「WXR に見えない」ではなく「その版には対応していない」と言う
        $this->assertStringContainsString('1.1', $error->getMessage());
        $this->assertStringContainsString('対応していません', $error->getMessage());
    }

    public function test_接頭辞がwpでなくても版を読む(): void
    {
        // 接頭辞は文書が決める。`wp:` 固定で探さない
        $path = $this->write('other-prefix.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"
                xmlns:content="http://purl.org/rss/1.0/modules/content/"
                xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
                xmlns:x="http://wordpress.org/export/1.2/">
            <channel><title>別の接頭辞</title>
            <x:wxr_version>1.2</x:wxr_version>
            <item><title>A</title><x:post_id>1</x:post_id><x:post_type>post</x:post_type></item>
            </channel></rss>
            XML);

        $ids = [];

        foreach ((new WxrReader($path))->items() as $item) {
            $ids[] = $item->postId;
        }

        $this->assertSame(['1'], $ids);
    }

    public function test_0件のwxrは正しく通る(): void
    {
        $path = $this->write('empty-wxr.xml', $this->wxr(''));

        $count = 0;

        foreach ((new WxrReader($path))->items() as $ignored) {
            $count++;
        }

        // 中身が無い WXR は「壊れている」ではない
        $this->assertSame(0, $count);
    }

    // ── libxml の設定を残さない（レビュー指摘 3） ──────────────

    public function test_読み終えたあとlibxmlの設定が戻る(): void
    {
        $before = libxml_use_internal_errors(false);
        libxml_use_internal_errors($before);

        foreach ((new WxrReader($this->fixture()))->items() as $ignored) {
            // 読み切る
        }

        $this->assertSame($before, $this->currentInternalErrors());
        $this->assertTrue($this->externalEntitiesRestored());
    }

    public function test_途中で止めてもlibxmlの設定が戻る(): void
    {
        $before = $this->currentInternalErrors();

        foreach ((new WxrReader($this->fixture()))->items() as $ignored) {
            // **1 件目で抜ける**（generator を途中で捨てる）
            break;
        }

        gc_collect_cycles();

        $this->assertSame($before, $this->currentInternalErrors());
        $this->assertTrue($this->externalEntitiesRestored());
    }

    public function test_例外で止まってもlibxmlの設定が戻る(): void
    {
        $before = $this->currentInternalErrors();
        $path = $this->write('dup.xml', $this->wxr(<<<'XML'
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type></item>
            <item><title>B</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type></item>
            XML));

        $this->errorFrom($path);

        $this->assertSame($before, $this->currentInternalErrors());
    }

    public function test_外部実体の検査そのものが成立している(): void
    {
        // 遮断されていれば false、戻っていれば true を返せること
        $this->assertTrue($this->externalEntitiesBlocked(), '遮断中でも通ってしまう検査は保証にならない');
        $this->assertTrue($this->externalEntitiesRestored());
    }

    public function test_読んだあとの別のxml処理に影響しない(): void
    {
        foreach ((new WxrReader($this->fixture()))->items() as $ignored) {
            break;
        }

        gc_collect_cycles();

        // 関係のない XML が、こちらの設定のせいで挙動を変えない
        $other = simplexml_load_string('<a><b>ok</b></a>');

        $this->assertNotFalse($other);
        $this->assertSame('ok', (string) $other->b);
    }

    // ── id は数字であることを確かめる ────────────────────────

    /*
     * WordPress の id は 10 進の整数である。`abc` を受けると、そのまま
     * 台帳の `external_id` に入り、**再実行と取り消しの鍵が壊れる**。
     * 長さも制限する（台帳の桁に収まらない値を後から見つけない）。
     */

    public function test_数字でないpost_idを断る(): void
    {
        $path = $this->write('bad-post-id.xml', $this->wxr(
            '<item><title>A</title><wp:post_id>abc</wp:post_id><wp:post_type>post</wp:post_type></item>',
        ));

        $this->assertStringContainsString('wp:post_id', $this->errorFrom($path)->getMessage());
    }

    public function test_数字でないcomment_idを断る(): void
    {
        $path = $this->write('bad-comment-id.xml', $this->wxr(<<<'XML'
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type>
            <wp:comment><wp:comment_id>abc</wp:comment_id></wp:comment>
            </item>
            XML));

        $this->assertStringContainsString('wp:comment_id', $this->errorFrom($path)->getMessage());
    }

    public function test_数字でないterm_idを断る(): void
    {
        $path = $this->write('bad-term-id.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
            <channel><wp:wxr_version>1.2</wp:wxr_version>
            <wp:category><wp:term_id>abc</wp:term_id>
            <wp:category_nicename>info</wp:category_nicename>
            <wp:cat_name><![CDATA[案内]]></wp:cat_name></wp:category>
            </channel></rss>
            XML);

        try {
            $this->definitionsOf($path);
            $this->fail('数字でない term_id を通しました');
        } catch (WxrError $e) {
            $this->assertStringContainsString('wp:term_id', $e->getMessage());
        }
    }

    public function test_数字でない親のidを断る(): void
    {
        // 変換する前に確かめる（`0` は「親なし」として正しい）
        $path = $this->write('bad-parent.xml', $this->wxr(
            '<item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type>'
            .'<wp:post_parent>abc</wp:post_parent></item>',
        ));

        $this->assertStringContainsString('wp:post_parent', $this->errorFrom($path)->getMessage());
    }

    public function test_長すぎるidを断る(): void
    {
        $path = $this->write('long-id.xml', $this->wxr(
            '<item><title>A</title><wp:post_id>'.str_repeat('9', 40).'</wp:post_id>'
            .'<wp:post_type>post</wp:post_type></item>',
        ));

        $this->assertStringContainsString('wp:post_id', $this->errorFrom($path)->getMessage());
    }

    public function test_数字でないcomment_parentを断る(): void
    {
        $path = $this->write('bad-comment-parent.xml', $this->wxr(<<<'XML'
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type>
            <wp:comment><wp:comment_id>101</wp:comment_id>
            <wp:comment_parent>abc</wp:comment_parent></wp:comment>
            </item>
            XML));

        $this->assertStringContainsString('wp:comment_parent', $this->errorFrom($path)->getMessage());
    }

    public function test_識別子としての0を断る(): void
    {
        /*
         * WordPress は `0` を「無い」の意味で使う。識別子として受けると、
         * **「親が無い」と「id が 0 の記事」を区別できなくなる**。
         */
        $path = $this->write('zero-id.xml', $this->wxr(
            '<item><title>A</title><wp:post_id>0</wp:post_id><wp:post_type>post</wp:post_type></item>',
        ));

        $this->assertStringContainsString('wp:post_id', $this->errorFrom($path)->getMessage());
    }

    public function test_先頭が0のidを断る(): void
    {
        // `01` と `1` が別物として台帳へ入る
        $path = $this->write('leading-zero-id.xml', $this->wxr(
            '<item><title>A</title><wp:post_id>01</wp:post_id><wp:post_type>post</wp:post_type></item>',
        ));

        $this->assertStringContainsString('wp:post_id', $this->errorFrom($path)->getMessage());
    }

    public function test_不正なpost_idの生の値を出さない(): void
    {
        /*
         * **不正な値そのものは出さない。** 見えない文字も本文級の長さも入り得る。
         * `wp:post_id` 自身が不正なときは、文脈として出せる id もまだ無い。
         */
        $path = $this->write('evil-id.xml', $this->wxr(
            '<item><title>A</title><wp:post_id>ab'.str_repeat('X', 300)
            .'</wp:post_id><wp:post_type>post</wp:post_type></item>',
        ));

        $error = $this->errorFrom($path);

        $this->assertStringNotContainsString('XXXX', $error->getMessage());
        $this->assertStringContainsString('wp:post_id', $error->getMessage());
    }

    public function test_版に改行や不可視文字があってもそのまま出さない(): void
    {
        /*
         * 実際に reader を通した経路で確かめる。
         * `WxrError` 側の単体テストだけでは、**呼ぶ側で生値を組み立てて渡す**
         * 経路が残っていても気づけない。
         */
        $path = $this->write('evil-version.xml', <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
            <channel><wp:wxr_version>1.1\u{2028}\u{202E}偽装</wp:wxr_version></channel></rss>
            XML);

        $error = $this->errorFrom($path);

        $this->assertStringNotContainsString("\u{2028}", $error->getMessage());
        $this->assertStringNotContainsString("\u{202E}", $error->getMessage());
        $this->assertStringNotContainsString("\n", $error->getMessage());
    }

    public function test_大きすぎて読めないときは理由を言う(): void
    {
        /*
         * `readOuterXml()` は**理由を返さずに空文字を返す**。
         * 実際には libxml の既定の上限に当たっていることがあり、
         * 「item を読めませんでした」だけでは**利用者に次の手が無い**。
         *
         * fixture は repo へ置かず、その場で作る（10MB を持ち歩かない）。
         */
        $huge = str_repeat('あ', 4 * 1024 * 1024);   // 約 12MB の text node

        $path = $this->write('huge-text-node.xml', $this->wxr(
            '<item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type>'
            .'<content:encoded><![CDATA['.$huge.']]></content:encoded></item>',
        ));

        try {
            $items = $this->itemsOf($path);

            // 読めたなら、読めたことを確かめて終わる（環境の上限は同じではない）
            $this->assertArrayHasKey('1', $items);
        } catch (WxrError $e) {
            // 読めなかったなら、**何が起きたか**が出ていること
            $this->assertStringContainsString('item', $e->getMessage());
            $this->assertStringContainsString('大きすぎる', $e->hint);

            /*
             * **libxml の言い分を実際に拾えていること**を固定する。
             * ここが定型文だけに戻ると、「読めませんでした」以上のことが
             * 言えない状態へ静かに戻ってしまう。
             */
            $this->assertNotSame('item を読めませんでした。', $e->getMessage());
            $this->assertNotNull($e->sourceLine);
        }
    }

    public function test_正しいidと親なしはそのまま通る(): void
    {
        $items = $this->itemsOf($this->fixture());

        $this->assertSame('11', $items['11']->postId);
        $this->assertNull($items['11']->parentId);
    }

    // ── 割り当てと meta を黙って捨てない（§A.3・§C.7） ──────

    public function test_domainの無い割り当てを断る(): void
    {
        /*
         * **定義側だけ fail-closed にして、割り当て側を黙って消さない。**
         * `domain` が無い `<category>` は、どの分類への割り当てか決められない。
         * 捨てると、その記事だけ分類が付かないまま「成功」で終わる。
         */
        $path = $this->write('no-domain.xml', $this->wxr(<<<'XML'
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type>
            <category nicename="news"><![CDATA[お知らせ]]></category>
            </item>
            XML));

        $error = $this->errorFrom($path);

        $this->assertStringContainsString('domain', $error->getMessage());
        $this->assertStringContainsString('1', $error->getMessage());
    }

    public function test_同じmeta_keyが2つある記事を断る(): void
    {
        /*
         * WordPress の postmeta は**同じ key を複数行持てる**。
         * §C.7 の保存形は 1 か所の object なので、複数値の意味が定義されていない。
         * 後から来た値で黙って上書きすると、**片方が理由も無く消える**。
         * どちらを採るかは決められないので止める。
         */
        $path = $this->write('dup-meta.xml', $this->wxr(<<<'XML'
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type>
            <wp:postmeta><wp:meta_key><![CDATA[subtitle]]></wp:meta_key>
              <wp:meta_value><![CDATA[ひとつめ]]></wp:meta_value></wp:postmeta>
            <wp:postmeta><wp:meta_key><![CDATA[subtitle]]></wp:meta_key>
              <wp:meta_value><![CDATA[ふたつめ]]></wp:meta_value></wp:postmeta>
            </item>
            XML));

        $error = $this->errorFrom($path);

        $this->assertStringContainsString('subtitle', $error->getMessage());
        // **値は出さない**（本文が画面と台帳へ流れる経路にしない）
        $this->assertStringNotContainsString('ひとつめ', $error->getMessage());
        $this->assertStringNotContainsString('ふたつめ', $error->getMessage());
    }

    public function test_nicenameの無い割り当てを断る(): void
    {
        // slug が無ければ、どの項目への割り当てか決められない
        $path = $this->write('no-nicename.xml', $this->wxr(<<<'XML'
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type>
            <category domain="category"><![CDATA[お知らせ]]></category>
            </item>
            XML));

        $this->assertStringContainsString('nicename', $this->errorFrom($path)->getMessage());
    }

    public function test_表示名の無い割り当てを断る(): void
    {
        $path = $this->write('no-term-label.xml', $this->wxr(<<<'XML'
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type>
            <category domain="category" nicename="news"></category>
            </item>
            XML));

        $this->assertStringContainsString('表示名', $this->errorFrom($path)->getMessage());
    }

    public function test_外から来た値は畳んでから出す(): void
    {
        /*
         * **本文級の長さの値が来る。** そのまま補間すると、
         * こちらの説明が上限まで押し出されて読めなくなる。
         */
        $huge = str_repeat('あ', 400);

        $path = $this->write('huge-meta-key.xml', $this->wxr(<<<XML
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type>
            <wp:postmeta><wp:meta_key><![CDATA[{$huge}]]></wp:meta_key>
              <wp:meta_value><![CDATA[x]]></wp:meta_value></wp:postmeta>
            <wp:postmeta><wp:meta_key><![CDATA[{$huge}]]></wp:meta_key>
              <wp:meta_value><![CDATA[y]]></wp:meta_value></wp:postmeta>
            </item>
            XML));

        $error = $this->errorFrom($path);

        // 説明の末尾（何をすればよいか）が残っている
        $this->assertStringContainsString('wp:post_id', $error->getMessage());
        $this->assertLessThanOrEqual(WxrError::MESSAGE_MAX_BYTES, strlen($error->getMessage()));
    }

    public function test_名前の無いmetaを断る(): void
    {
        /*
         * 名前の無い値は §C.7 の object へ入れられない。
         * そして**捨てる決まりも無い**（捨てる決まりがあるのは `_` 始まりだけ）。
         */
        $path = $this->write('empty-meta-key.xml', $this->wxr(<<<'XML'
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type>
            <wp:postmeta><wp:meta_key><![CDATA[]]></wp:meta_key>
              <wp:meta_value><![CDATA[名前の無い値]]></wp:meta_value></wp:postmeta>
            </item>
            XML));

        $error = $this->errorFrom($path);

        $this->assertStringContainsString('wp:postmeta', $error->getMessage());
        $this->assertStringContainsString('1', $error->getMessage());
        $this->assertStringNotContainsString('名前の無い値', $error->getMessage());
    }

    public function test_内部用のmeta_keyは重複していても数に入れない(): void
    {
        // `_` で始まるものは捨てる決まりなので、重複しても関係がない
        $path = $this->write('dup-internal-meta.xml', $this->wxr(<<<'XML'
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type>
            <wp:postmeta><wp:meta_key><![CDATA[_edit_last]]></wp:meta_key>
              <wp:meta_value><![CDATA[1]]></wp:meta_value></wp:postmeta>
            <wp:postmeta><wp:meta_key><![CDATA[_edit_last]]></wp:meta_key>
              <wp:meta_value><![CDATA[2]]></wp:meta_value></wp:postmeta>
            </item>
            XML));

        $this->assertSame([], $this->itemsOf($path)['1']->meta);
    }

    // ── コメントの id（§A.3・§D.2） ─────────────────────────

    /*
     * **コメントは他人が書いた文章である。** 黙って捨てると、
     * 移行が終わったあとに「あのコメントが無い」と気づく人が出る。
     * そして `comment_id` は台帳（`import_records.external_id`）の鍵でもあるので、
     * 無いものは**冪等性も取り消しも成立しない**。
     */

    public function test_comment_idの無いコメントを断る(): void
    {
        $path = $this->write('no-comment-id.xml', $this->wxr(<<<'XML'
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type>
            <wp:comment>
              <wp:comment_author><![CDATA[読者]]></wp:comment_author>
              <wp:comment_content><![CDATA[これは消えてはいけない]]></wp:comment_content>
              <wp:comment_approved>1</wp:comment_approved>
            </wp:comment>
            </item>
            XML));

        $error = $this->errorFrom($path);

        $this->assertStringContainsString('comment_id', $error->getMessage());
        // どの記事のことか分かるように言う
        $this->assertStringContainsString('1', $error->getMessage());
    }

    public function test_同じ記事の中でcomment_idが重複したら断る(): void
    {
        $path = $this->write('dup-comment-in-item.xml', $this->wxr(<<<'XML'
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type>
            <wp:comment><wp:comment_id>101</wp:comment_id>
              <wp:comment_content><![CDATA[ひとつめ]]></wp:comment_content></wp:comment>
            <wp:comment><wp:comment_id>101</wp:comment_id>
              <wp:comment_content><![CDATA[ふたつめ]]></wp:comment_content></wp:comment>
            </item>
            XML));

        $this->assertStringContainsString('101', $this->errorFrom($path)->getMessage());
    }

    public function test_別の記事とcomment_idが重複したら断る(): void
    {
        /*
         * 台帳は `(kind, target_type, external_id)` で一意である。
         * 記事をまたいで同じ id があると、**2 件目が黙って飛ばされる**。
         */
        $path = $this->write('dup-comment-across-items.xml', $this->wxr(<<<'XML'
            <item><title>A</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type>
            <wp:comment><wp:comment_id>101</wp:comment_id>
              <wp:comment_content><![CDATA[記事Aのコメント]]></wp:comment_content></wp:comment>
            </item>
            <item><title>B</title><wp:post_id>2</wp:post_id><wp:post_type>post</wp:post_type>
            <wp:comment><wp:comment_id>101</wp:comment_id>
              <wp:comment_content><![CDATA[記事Bのコメント]]></wp:comment_content></wp:comment>
            </item>
            XML));

        $this->assertStringContainsString('101', $this->errorFrom($path)->getMessage());
    }

    public function test_正しいcomment_idはそのまま読める(): void
    {
        $items = $this->itemsOf($this->fixture());

        $this->assertSame(['101', '102'], array_column($items['11']->comments, 'commentId'));
    }

    // ── 分類の定義（channel 直下） ──────────────────────────

    /*
     * **親子は item の中には無い。** item の `<category domain nicename>` は
     * 「この記事はこの項目に属する」という割り当てで、親が誰かは書かれていない。
     * 親子は channel 直下の `<wp:category>` / `<wp:term>` にしか無い。
     *
     * ここを読まないと §C.3 の「親から先に作る」が成立せず、
     * **入れ子だった分類が全部フラットに並ぶ**。しかも取り込みは成功に見える。
     */

    public function test_channel直下の分類定義を読める(): void
    {
        $definitions = $this->definitionsOf($this->fixture());

        $this->assertSame(['category:info', 'category:news', 'post_tag:launch', 'product_line:alpha'],
            array_keys($definitions));
    }

    public function test_親のslugを読める(): void
    {
        $definitions = $this->definitionsOf($this->fixture());

        $this->assertNull($definitions['category:info']->parentSlug);
        // WXR 1.2 の category_parent は id ではなく **slug**
        $this->assertSame('info', $definitions['category:news']->parentSlug);
    }

    public function test_3つの書き方をどれも読める(): void
    {
        $definitions = $this->definitionsOf($this->fixture());

        // <wp:category> は taxonomy 名を持たない。category と決まっている
        $this->assertSame('category', $definitions['category:news']->taxonomy);
        $this->assertSame('お知らせ', $definitions['category:news']->name);
        $this->assertSame('3', $definitions['category:news']->termId);

        // <wp:tag> は post_tag（WordPress 側の分類名に合わせる）
        $this->assertSame('post_tag', $definitions['post_tag:launch']->taxonomy);
        $this->assertSame('ローンチ', $definitions['post_tag:launch']->name);

        // <wp:term> は wp:term_taxonomy が分類名になる
        $this->assertSame('product_line', $definitions['product_line:alpha']->taxonomy);
        $this->assertSame('アルファ', $definitions['product_line:alpha']->name);
    }

    public function test_接頭辞がwpでなくても分類定義を読む(): void
    {
        $path = $this->write('other-prefix-terms.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:x="http://wordpress.org/export/1.2/">
            <channel><x:wxr_version>1.2</x:wxr_version>
            <x:category>
              <x:term_id>2</x:term_id>
              <x:category_nicename>info</x:category_nicename>
              <x:cat_name><![CDATA[案内]]></x:cat_name>
            </x:category>
            </channel></rss>
            XML);

        $this->assertSame(['category:info'], array_keys($this->definitionsOf($path)));
    }

    public function test_分類定義でも外部実体を通さない(): void
    {
        // **別口だけ緩くしない。** items() と同じ入口を通る
        $secret = $this->dir.'/secret.txt';
        file_put_contents($secret, 'APP_KEY=base64:dont-read-me');

        $path = $this->write('xxe-terms.xml', <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE rss [<!ENTITY xxe SYSTEM "file://{$secret}">]>
            <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
            <channel><wp:wxr_version>1.2</wp:wxr_version>
            <wp:category><wp:term_id>2</wp:term_id>
            <wp:category_nicename>info</wp:category_nicename>
            <wp:cat_name>&xxe;</wp:cat_name></wp:category>
            </channel></rss>
            XML);

        try {
            $this->definitionsOf($path);
            $this->fail('DOCTYPE を通しました');
        } catch (WxrError $e) {
            $this->assertStringContainsString('DOCTYPE', $e->getMessage());
        }
    }

    public function test_分類定義でもwxrでなければ断る(): void
    {
        $path = $this->write('not-wxr-terms.xml', '<?xml version="1.0"?><catalog><book id="1"/></catalog>');

        try {
            $this->definitionsOf($path);
            $this->fail('WXR でないものを通しました');
        } catch (WxrError $e) {
            $this->assertStringContainsString('WXR', $e->getMessage());
        }
    }

    public function test_slugの無い分類定義を断る(): void
    {
        /*
         * slug が無ければ**どの項目のことか決められない**。
         * 空のまま作ると、名前だけ同じ別物が増えていく。
         */
        $path = $this->write('no-slug.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
            <channel><wp:wxr_version>1.2</wp:wxr_version>
            <wp:category><wp:term_id>2</wp:term_id>
            <wp:cat_name><![CDATA[名前だけ]]></wp:cat_name></wp:category>
            </channel></rss>
            XML);

        try {
            $this->definitionsOf($path);
            $this->fail('slug の無い定義を通しました');
        } catch (WxrError $e) {
            $this->assertStringContainsString('slug', $e->getMessage());
        }
    }

    public function test_term_idの無い分類定義を断る(): void
    {
        $path = $this->write('no-term-id.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
            <channel><wp:wxr_version>1.2</wp:wxr_version>
            <wp:category>
            <wp:category_nicename>info</wp:category_nicename>
            <wp:cat_name><![CDATA[案内]]></wp:cat_name></wp:category>
            </channel></rss>
            XML);

        try {
            $this->definitionsOf($path);
            $this->fail('term_id の無い定義を通しました');
        } catch (WxrError $e) {
            $this->assertStringContainsString('term_id', $e->getMessage());
        }
    }

    public function test_別の版の名前空間にある分類定義を断る(): void
    {
        /*
         * **27B で直した版の食い違いを、別の口で再発させない。**
         * 文書が 1.2 を名乗っていても `<old:category xmlns:old=…/1.1/>` は別の語彙である。
         * 無視すればその分類が黙って落ち、拾えば 1.1 を 1.2 として読む。
         */
        $path = $this->write('mixed-version-term.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"
                xmlns:wp="http://wordpress.org/export/1.2/"
                xmlns:old="http://wordpress.org/export/1.1/">
            <channel><wp:wxr_version>1.2</wp:wxr_version>
            <old:category>
              <old:term_id>2</old:term_id>
              <old:category_nicename>info</old:category_nicename>
              <old:cat_name><![CDATA[案内]]></old:cat_name>
            </old:category>
            </channel></rss>
            XML);

        try {
            $this->definitionsOf($path);
            $this->fail('別の版の名前空間にある定義を通しました');
        } catch (WxrError $e) {
            $this->assertStringContainsString('1.1', $e->getMessage());
            $this->assertStringContainsString('1.2', $e->getMessage());
        }
    }

    public function test_term_taxonomyの無いterm定義を断る(): void
    {
        // `<wp:term>` だけは分類名を自分で名乗る。無ければ受け皿を選べない
        $path = $this->write('no-term-taxonomy.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
            <channel><wp:wxr_version>1.2</wp:wxr_version>
            <wp:term>
              <wp:term_id>9</wp:term_id>
              <wp:term_slug>alpha</wp:term_slug>
              <wp:term_name><![CDATA[アルファ]]></wp:term_name>
            </wp:term>
            </channel></rss>
            XML);

        try {
            $this->definitionsOf($path);
            $this->fail('term_taxonomy の無い定義を通しました');
        } catch (WxrError $e) {
            $this->assertStringContainsString('term_taxonomy', $e->getMessage());
        }
    }

    public function test_表示名の無い分類定義を断る(): void
    {
        /*
         * id と slug が揃っていても名前が空なら項目は作れない。
         * ここで通すと **plan は「作れる」と言い、apply が 1 件ずつ落ちる**。
         * 「plan で全部出す」が守れなくなる。
         */
        $path = $this->write('no-term-name.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
            <channel><wp:wxr_version>1.2</wp:wxr_version>
            <wp:category>
              <wp:term_id>2</wp:term_id>
              <wp:category_nicename>info</wp:category_nicename>
              <wp:cat_name></wp:cat_name>
            </wp:category>
            </channel></rss>
            XML);

        try {
            $this->definitionsOf($path);
            $this->fail('表示名の無い定義を通しました');
        } catch (WxrError $e) {
            $this->assertStringContainsString('表示名', $e->getMessage());
        }
    }

    public function test_分類定義がchannelの直下になければ断る(): void
    {
        $path = $this->write('term-outside.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
            <channel><wp:wxr_version>1.2</wp:wxr_version>
            <wp:extra><wp:category>
              <wp:term_id>2</wp:term_id>
              <wp:category_nicename>info</wp:category_nicename>
              <wp:cat_name><![CDATA[案内]]></wp:cat_name>
            </wp:category></wp:extra>
            </channel></rss>
            XML);

        try {
            $this->definitionsOf($path);
            $this->fail('channel の直下に無い定義を通しました');
        } catch (WxrError $e) {
            $this->assertStringContainsString('channel', $e->hint);
        }
    }

    public function test_分類定義を途中で止めてもlibxmlの設定が戻る(): void
    {
        $before = $this->currentInternalErrors();

        foreach ((new WxrReader($this->fixture()))->termDefinitions() as $ignored) {
            break;
        }

        gc_collect_cycles();

        $this->assertSame($before, $this->currentInternalErrors());
        $this->assertTrue($this->externalEntitiesRestored());
    }

    public function test_割り当てには親を持たせない(): void
    {
        /*
         * item 側の割り当ては `domain` / `nicename` / `name` だけである。
         * **埋まらないキーを持たせない。** 常に空の `parent` があると、
         * 下流は「階層を扱えている」ように見えて扱えていない状態になる。
         */
        $first = $this->itemsOf($this->fixture())['11'];

        $this->assertSame(['domain', 'nicename', 'name'], array_keys($first->terms[0]));
    }

    // ── 読めるもの ──────────────────────────────────────────

    public function test_実際のwxrを読める(): void
    {
        $summary = $this->summarize($this->fixture());
        $data = $summary->toArray();

        $this->assertSame(4, $data['items']);
        $this->assertSame(2, $data['postTypes']['post']);
        $this->assertSame(1, $data['postTypes']['page']);
        $this->assertSame(1, $data['attachments']);
        $this->assertSame(2, $data['comments']);
    }

    public function test_本文と抜粋と分類を読める(): void
    {
        $items = $this->itemsOf($this->fixture());
        $first = $items['11'];

        $this->assertSame('はじめての記事', $first->title);
        $this->assertSame('first-post', $first->slug);
        $this->assertStringContainsString('<p>本文です。</p>', $first->content);
        $this->assertSame('抜粋です', $first->excerpt);

        $domains = array_column($first->terms, 'domain');

        $this->assertSame(['category', 'post_tag'], $domains);
    }

    /**
     * 公開日時は **UTC の `post_date_gmt` をアプリの時間帯へ直して**返る（F-17）。
     *
     * 時間帯をここで名指しするのは、**環境の `app.timezone` に結果を預けない**ため。
     * 預けると、`.env` の有無で同じ test が別の値を期待することになる。
     * 変換そのものの網は `WxrPublishedAtTimezoneTest` が張る。
     */
    public function test_公開日時はアプリの時間帯で返る(): void
    {
        date_default_timezone_set('Asia/Tokyo');

        // fixture は post_date 10:00 / post_date_gmt 01:00
        $this->assertSame('2024-05-01 10:00:00', $this->itemsOf($this->fixture())['11']->publishedAt);

        date_default_timezone_set('UTC');

        $this->assertSame('2024-05-01 01:00:00', $this->itemsOf($this->fixture())['11']->publishedAt);
    }

    public function test_内部用のmetaを捨てserializeは解釈しない(): void
    {
        $first = $this->itemsOf($this->fixture())['11'];

        // `_` で始まるものは WP の内部用
        $this->assertArrayNotHasKey('_edit_last', $first->meta);
        // 壊れていても落ちないよう、文字列のまま持つ
        $this->assertSame('a:1:{s:3:"key";s:5:"value";}', $first->meta['subtitle']);
    }

    public function test_コメントを読むがipは持たない(): void
    {
        $first = $this->itemsOf($this->fixture())['11'];

        $this->assertCount(2, $first->comments);
        $this->assertSame('読者', $first->comments[0]->author);
        $this->assertSame('1', $first->comments[0]->approved);
        $this->assertSame('101', $first->comments[1]->parentId);

        // 生の IP を持たない方針。**形に入れない**
        $dump = json_encode($first->comments, JSON_UNESCAPED_UNICODE) ?: '';

        $this->assertStringNotContainsString('203.0.113.9', $dump);
        $this->assertStringNotContainsString('ip', strtolower($dump));
    }

    public function test_0000で始まる日付は無いものとして扱う(): void
    {
        $first = $this->itemsOf($this->fixture())['11'];

        $this->assertNull($first->comments[1]->createdAt);
    }

    public function test_shortcodeを数えるが展開も削除もしない(): void
    {
        $data = $this->summarize($this->fixture())->toArray();

        $this->assertSame(1, $data['shortcodes']['gallery']);

        // 本文はそのまま
        $this->assertStringContainsString('[gallery ids="1,2"]', $this->itemsOf($this->fixture())['11']->content);
    }

    public function test_添付のurlを読める(): void
    {
        $this->assertSame(
            'https://old.example.test/wp-content/uploads/2024/05/photo.jpg',
            $this->itemsOf($this->fixture())['13']->attachmentUrl,
        );
    }

    public function test_同じファイルなら指紋が同じ(): void
    {
        $reader = new WxrReader($this->fixture());

        $this->assertSame($reader->fingerprint(), (new WxrReader($this->fixture()))->fingerprint());
        $this->assertSame(64, strlen($reader->fingerprint()));
    }

    // ── コマンド（§D.1） ────────────────────────────────────

    public function test_inspectはdbを1行も読まない(): void
    {
        // 接続そのものを外す。触れば必ず落ちる
        config(['database.default' => 'no-such-connection']);

        $this->artisan('agentcms:wxr-inspect', ['file' => $this->fixture()])
            ->assertSuccessful();
    }

    public function test_inspectは取り込まないものに理由を添える(): void
    {
        $this->artisan('agentcms:wxr-inspect', ['file' => $this->fixture()])
            // ページは release-20260909 から入る（pages へ）。理由の文言もそれに合わせる
            ->expectsOutputToContain('ページとして取り込みます')
            ->expectsOutputToContain('--media を付けたときだけ')
            ->assertSuccessful();
    }

    public function test_inspectは壊れたファイルで失敗する(): void
    {
        $this->artisan('agentcms:wxr-inspect', ['file' => $this->dir.'/none.xml'])
            ->assertFailed();
    }

    // ── ヘルパー ────────────────────────────────────────────

    private function fixture(): string
    {
        return base_path('tests/fixtures/wxr/sample.xml');
    }

    /** 正しい WXR 1.2 の殻に item を入れる */
    // ── 記事がコメントを受け付けるか（27D-5c・§C.4.3） ────────

    public function test_コメントの受付設定を運ぶ(): void
    {
        /*
         * **読み替えない・畳まない。** WordPress の語彙のまま運ぶ（`WxrItem` の docblock）。
         * ここで真偽値へ畳むと、「open でも closed でもない値が書いてあった」を
         * 下流が知る手立てが無くなる。
         */
        $path = $this->write('a.xml', $this->wxr(
            $this->entry('11', '<wp:comment_status>closed</wp:comment_status>')
            .$this->entry('12', '<wp:comment_status>open</wp:comment_status>')
            .$this->entry('13', '<wp:comment_status>registered_only</wp:comment_status>'),
        ));

        $items = $this->itemsOf($path);

        $this->assertSame('closed', $items['11']->commentStatus);
        $this->assertSame('open', $items['12']->commentStatus);
        $this->assertSame('registered_only', $items['13']->commentStatus);
    }

    public function test_受付設定が無ければ何も運ばない(): void
    {
        // **要素が無いのは正常な不在**（§C.4.3）。空文字も同じ扱いにする
        $path = $this->write('a.xml', $this->wxr(
            $this->entry('11', '')
            .$this->entry('12', '<wp:comment_status></wp:comment_status>'),
        ));

        $items = $this->itemsOf($path);

        $this->assertNull($items['11']->commentStatus);
        $this->assertNull($items['12']->commentStatus);
    }

    private function entry(string $id, string $extra): string
    {
        return "<item><title>記事 {$id}</title><wp:post_id>{$id}</wp:post_id>"
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            ."<wp:post_name>kiji-{$id}</wp:post_name>"
            ."<content:encoded><![CDATA[本文 {$id}]]></content:encoded>"
            .$extra
            .'</item>';
    }

    private function wxr(string $items): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"'
            .' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
            .' xmlns:wp="http://wordpress.org/export/1.2/">'
            .'<channel><title>移行元</title><wp:wxr_version>1.2</wp:wxr_version>'
            .$items
            .'</channel></rss>';
    }

    /** いまの `libxml_use_internal_errors` の値（読むだけ） */
    private function currentInternalErrors(): bool
    {
        $current = libxml_use_internal_errors(false);
        libxml_use_internal_errors($current);

        return $current;
    }

    /**
     * 外部実体の loader が既定へ戻っているか。
     *
     * **本物の外部実体を 1 つ解決させて確かめる。**
     * `<!DOCTYPE a><a>ok</a>` のように実体を参照しない文書では、
     * loader が遮断されたままでも通ってしまい、名前と保証が一致しない。
     *
     * 読ませるのは**この test が作った無害な合言葉のファイル**だけである
     * （秘密や実環境のファイルは使わない）。
     */
    private function externalEntitiesRestored(): bool
    {
        $sentinel = $this->dir.'/sentinel.txt';
        file_put_contents($sentinel, 'entity-loader-is-back');

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $xml = '<?xml version="1.0"?>'
            ."<!DOCTYPE a [<!ENTITY s SYSTEM \"file://{$sentinel}\">]>"
            .'<a>&s;</a>';

        // LIBXML_NOENT で実体を展開させる = loader を実際に通す
        $result = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOENT);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $result !== false && str_contains((string) $result, 'entity-loader-is-back');
    }

    /** 上の検査そのものが成立していること（遮断中なら false を返せること） */
    private function externalEntitiesBlocked(): bool
    {
        libxml_set_external_entity_loader(static fn (): null => null);

        try {
            return ! $this->externalEntitiesRestored();
        } finally {
            libxml_set_external_entity_loader(null);
        }
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function errorFrom(string $path): WxrError
    {
        try {
            foreach ((new WxrReader($path))->items() as $ignored) {
                // 読み切る前に落ちるのが期待
            }
        } catch (WxrError $e) {
            return $e;
        }

        $this->fail("{$path} が受け入れられてしまいました");
    }

    /** @return array<string, WxrTermDefinition> `分類:slug` をキーにして返す */
    private function definitionsOf(string $path): array
    {
        $definitions = [];

        foreach ((new WxrReader($path))->termDefinitions() as $definition) {
            $definitions[$definition->taxonomy.':'.$definition->slug] = $definition;
        }

        return $definitions;
    }

    private function summarize(string $path): WxrSummary
    {
        $summary = new WxrSummary;

        foreach ((new WxrReader($path))->items() as $item) {
            $summary->add($item);
        }

        return $summary;
    }

    /** @return array<string, WxrItem> */
    private function itemsOf(string $path): array
    {
        $items = [];

        foreach ((new WxrReader($path))->items() as $item) {
            $items[$item->postId] = $item;
        }

        return $items;
    }
}
