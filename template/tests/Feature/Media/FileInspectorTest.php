<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use AgentCms\Core\Uploads\FileInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

/**
 * 一般ファイルの実体検査（Phase 9.5-D / SPEC §3.8）。
 *
 * 拡張子も申告 MIME も信用しない。**中身が拡張子のとおりか**だけを見る。
 * OOXML は **展開せずに** 中央ディレクトリだけで判定する（展開した時点で zip bomb を踏む）。
 */
final class FileInspectorTest extends TestCase
{
    private FileInspector $inspector;

    /** @var list<string> */
    private array $temporaries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->inspector = new FileInspector;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaries as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = $this->temporaryPath();
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function temporaryPath(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'agentcms-test-');
        $this->temporaries[] = $path;

        return $path;
    }

    private function pdf(string $body = "1 0 obj\n<<>>\nendobj\n"): string
    {
        return "%PDF-1.7\n".$body."trailer\n<<>>\nstartxref\n0\n%%EOF\n";
    }

    /** @param array<string, string> $entries */
    private function zip(array $entries, ?callable $configure = null): string
    {
        $path = $this->temporaryPath();
        @unlink($path);

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        if ($configure !== null) {
            $configure($zip);
        }

        $zip->close();
        $this->temporaries[] = $path;

        return (string) file_get_contents($path);
    }

    /** 最小の docx（`[Content_Types].xml` と `word/document.xml` を持つ ZIP） */
    private function docx(): string
    {
        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships/>',
            'word/document.xml' => '<?xml version="1.0"?><document/>',
        ]);
    }

    private function assertRejected(string $name, string $content, string $expectedFragment = ''): void
    {
        try {
            $this->inspector->inspect($this->upload($name, $content));
            $this->fail("{$name} が受理されてしまった");
        } catch (ValidationException $e) {
            // 拒否の理由は `file` キーに入る（呼び出し側がそのまま画面へ出せる）
            $this->assertArrayHasKey('file', $e->errors());

            if ($expectedFragment !== '') {
                $this->assertStringContainsString($expectedFragment, (string) $e->getMessage());
            }
        }
    }

    // --- 受け入れ -------------------------------------------------------

    public function test_a_real_pdf_is_accepted(): void
    {
        $result = $this->inspector->inspect($this->upload('resume.pdf', $this->pdf()));

        $this->assertSame('pdf', $result['extension']);
        $this->assertSame('application/pdf', $result['mime']);
    }

    public function test_utf8_text_is_accepted(): void
    {
        $result = $this->inspector->inspect($this->upload('memo.txt', "これは日本語のメモです。\n"));

        $this->assertSame('txt', $result['extension']);
    }

    public function test_shift_jis_csv_is_accepted_without_being_converted(): void
    {
        // Excel が書き出す CSV は CP932 のままであることが普通。変換すると原本と食い違う
        $content = (string) mb_convert_encoding("氏名,年齢\n山田,32\n", 'CP932', 'UTF-8');

        $result = $this->inspector->inspect($this->upload('list.csv', $content));

        $this->assertSame('csv', $result['extension']);
    }

    public function test_a_minimal_docx_is_accepted(): void
    {
        $result = $this->inspector->inspect($this->upload('doc.docx', $this->docx()));

        $this->assertSame('docx', $result['extension']);
    }

    // --- 拡張子と中身の食い違い ----------------------------------------

    public function test_an_unlisted_extension_is_rejected(): void
    {
        $this->assertRejected('script.php', '<?php echo 1;', 'この形式のファイルは登録できません');
    }

    /**
     * SPEC §9 の matrix: ZIP・HTML・JS・exe・sh・php・SVG は一般ファイルとして受けない。
     * マクロ付きの拡張子（docm / xlsm / pptm）も同じく許可表に無い。
     */
    #[DataProvider('unlistedExtensions')]
    public function test_dangerous_extensions_never_reach_the_content_check(string $name): void
    {
        $this->assertRejected($name, 'なんらかの中身', 'この形式のファイルは登録できません');
    }

    /** @return array<string, array{string}> */
    public static function unlistedExtensions(): array
    {
        $names = [
            'archive.zip', 'page.html', 'app.js', 'setup.exe', 'run.sh',
            'shell.php', 'logo.svg', 'macro.docm', 'macro.xlsm', 'macro.pptm',
        ];

        return array_combine($names, array_map(static fn (string $n): array => [$n], $names));
    }

    public function test_an_executable_renamed_to_pdf_is_rejected(): void
    {
        $this->assertRejected('payload.pdf', "MZ\x90\x00".str_repeat("\x00", 128));
    }

    public function test_a_pdf_without_the_signature_is_rejected(): void
    {
        // finfo が application/pdf と言うだけでは通さない
        $this->assertRejected('fake.pdf', "not a pdf\n%%EOF\n");
    }

    public function test_a_pdf_without_eof_is_rejected(): void
    {
        $this->assertRejected('truncated.pdf', "%PDF-1.7\n".str_repeat('A', 4096));
    }

    public function test_html_renamed_to_txt_is_rejected(): void
    {
        // ブラウザに解釈され得るものをテキストとして受けない
        $this->assertRejected('page.txt', "<html><body>hi</body></html>\n");
    }

    public function test_php_source_renamed_to_csv_is_rejected(): void
    {
        $this->assertRejected('data.csv', "a,b\n<?php system('id'); ?>\n");
    }

    public function test_text_with_a_null_byte_is_rejected(): void
    {
        $this->assertRejected('binary.txt', "hello\x00world");
    }

    public function test_utf16_text_is_rejected(): void
    {
        $this->assertRejected('utf16.txt', "\xff\xfeh\x00e\x00l\x00l\x00o\x00");
    }

    public function test_a_docx_that_is_really_a_pdf_is_rejected(): void
    {
        $this->assertRejected('trick.docx', $this->pdf());
    }

    // --- OOXML の bounded 検査 -----------------------------------------

    public function test_a_plain_zip_renamed_to_docx_is_rejected(): void
    {
        $this->assertRejected('plain.docx', $this->zip(['readme.txt' => 'hello']));
    }

    public function test_an_xlsx_shaped_file_cannot_be_uploaded_as_docx(): void
    {
        $xlsx = $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook/>',
        ]);

        $this->assertRejected('sheet.docx', $xlsx);
    }

    public function test_a_macro_bearing_document_is_rejected(): void
    {
        $withMacro = $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
            'word/document.xml' => '<?xml version="1.0"?><document/>',
            'word/vbaProject.bin' => 'macro',
        ]);

        $this->assertRejected('macro.docx', $withMacro, 'マクロを含むファイル');
    }

    public function test_a_zip_bomb_is_rejected_by_the_compression_ratio(): void
    {
        $bomb = $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
            'word/document.xml' => str_repeat("\0", 2 * 1024 * 1024),
        ]);

        $this->assertRejected('bomb.docx', $bomb);
    }

    public function test_too_many_entries_are_rejected(): void
    {
        config()->set('agentcms.files.ooxml.max_entries', 2);

        $this->assertRejected('many.docx', $this->docx());
    }

    public function test_total_uncompressed_size_is_bounded(): void
    {
        config()->set('agentcms.files.ooxml.max_uncompressed_bytes', 16);

        $this->assertRejected('big.docx', $this->docx());
    }

    public function test_a_path_traversal_entry_is_rejected(): void
    {
        $evil = $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
            'word/document.xml' => '<?xml version="1.0"?><document/>',
            '../../etc/passwd' => 'root',
        ]);

        $this->assertRejected('traversal.docx', $evil);
    }

    // --- 実体を読めない ------------------------------------------------

    /**
     * 実体を読めないなら何ひとつ確かめられないので、通してはいけない。
     *
     * signature を持つ形式は「読めない = 一致しない」で結果的に落ちるが、
     * TXT / CSV は「何でないか」で守っているため、**空の内容は素通りしてしまう**。
     */
    public function test_an_unreadable_upload_is_rejected_instead_of_slipping_through(): void
    {
        foreach (['memo.txt', 'list.csv', 'doc.pdf', 'doc.docx'] as $name) {
            $upload = $this->upload($name, 'なんらかの中身');
            @unlink($upload->getPathname());

            try {
                $this->inspector->inspect($upload);
                $this->fail("{$name}: 実体が無いのに受理された");
            } catch (ValidationException $e) {
                $this->assertSame(['ファイルを読み込めませんでした'], $e->errors()['file']);
            }
        }
    }

    // --- 上限 -----------------------------------------------------------

    public function test_a_file_over_the_size_limit_is_rejected(): void
    {
        config()->set('agentcms.files.max_kilobytes', 1);

        $this->assertRejected('big.pdf', $this->pdf(str_repeat('A', 4096)), '上限');
    }
}
