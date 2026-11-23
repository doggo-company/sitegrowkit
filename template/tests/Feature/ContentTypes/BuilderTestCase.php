<?php

declare(strict_types=1);

namespace Tests\Feature\ContentTypes;

use AgentCms\Core\ContentTypes\ContentTypeDraft;
use App\Models\User;
use FilesystemIterator;
use Illuminate\Support\Facades\Schema;
use Inertia\Support\SessionKey;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Content Type Builder のテスト基盤（Phase 9.4 / STAGE #17A・SPEC §2）。
 *
 * ## 実プロジェクトを fixture にしない
 *
 * 生成テストは **一時ディレクトリと一時 SQLite ファイル**の中だけで動く。
 * `content/schemas` / `modules/` / ローカル確認用 DB は読み取るだけで、
 * 1 バイトも変更しない（`assertProjectUntouched()` が毎回確かめる）。
 *
 * ## なぜ in-memory SQLite ではないのか
 *
 * 「作成した投稿タイプが**次のリクエストから**管理画面に出る」ことを
 * 確かめるには、アプリケーションを作り直して route 登録からやり直す必要がある。
 * `:memory:` は接続を作り直した瞬間に中身が消えるので、ファイル DB を使う。
 */
abstract class BuilderTestCase extends TestCase
{
    /**
     * このテストが作る Content Type の ID。
     *
     * **テストごとに変える。** 生成されたモデルクラスは
     * 一度読み込むとプロセスに残るため、同じ ID を使い回すと
     * 2 つ目以降のテストが「クラスが既に定義されています」で落ちる
     * （これは本番では正しい防御であり、緩めるべきではない）。
     */
    protected string $typeId = 'faq';

    protected string $typeStudly = 'Faq';

    private static int $typeCounter = 0;

    /** 生成物を閉じ込める一時ルート */
    protected string $sandbox;

    protected string $schemaPath;

    protected string $modulesPath;

    protected string $databaseFile;

    /** 生成されたモデルを読み込むための一時 autoloader（プロセスで 1 度だけ登録） */
    private static ?string $autoloadRoot = null;

    private static bool $autoloaderRegistered = false;

    /** @var array<string, string|false> 復元用に控えた環境変数 */
    private array $originalEnv = [];

    /** 実プロジェクトの状態（tearDown 後の不変を確かめる） */
    private string $projectFingerprintBefore = '';

    protected function setUp(): void
    {
        self::$typeCounter++;
        $this->typeId = 'faq'.self::$typeCounter;
        $this->typeStudly = 'Faq'.self::$typeCounter;

        $this->sandbox = sys_get_temp_dir().'/agentcms-ct-'.bin2hex(random_bytes(8));
        $this->schemaPath = $this->sandbox.'/content/schemas';
        $this->modulesPath = $this->sandbox.'/modules';
        $this->databaseFile = $this->sandbox.'/testing.sqlite';

        // Facade はアプリ生成前なので使わない（素の PHP で用意する）
        self::makeDirectory($this->schemaPath);
        self::makeDirectory($this->modulesPath);

        // 既存 Content Type（news / works / product …）はそのまま解決できる必要がある。
        // **原本は読むだけ**で、書き込み先は複製した sandbox 側だけにする
        self::copyTree(self::projectPath('content/schemas'), $this->schemaPath);
        self::copyTree(self::projectPath('modules'), $this->modulesPath);

        touch($this->databaseFile);

        $this->projectFingerprintBefore = $this->projectFingerprint();

        $this->setEnv('AGENTCMS_SCHEMA_PATH', $this->schemaPath);
        $this->setEnv('AGENTCMS_MODULES_PATH', $this->modulesPath);
        $this->setEnv('DB_CONNECTION', 'sqlite');
        $this->setEnv('DB_DATABASE', $this->databaseFile);

        self::$autoloadRoot = $this->modulesPath;
        self::registerSandboxAutoloader();

        parent::setUp();

        $this->artisan('migrate', ['--force' => true])->run();
    }

    protected function tearDown(): void
    {
        $fingerprintAfter = $this->projectFingerprint();

        parent::tearDown();

        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $this->originalEnv = [];
        self::$autoloadRoot = null;

        self::deleteTree($this->sandbox);

        // 実プロジェクトの schema / modules は 1 バイトも変わっていないこと（SPEC §2-19）
        $this->assertSame(
            $this->projectFingerprintBefore,
            $fingerprintAfter,
            '実プロジェクトの content/schemas または modules/ が変更されています',
        );
    }

    /** ログイン済み管理者 */
    protected function admin(): User
    {
        return User::factory()->create();
    }

    /**
     * 作成に使う最小の入力。
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_replace([
            'id' => $this->typeId,
            'label' => 'よくある質問',
            'fields' => [
                [
                    'key' => 'title', 'type' => 'text', 'label' => '質問',
                    'required' => true, 'max' => 120, 'searchable' => true, 'choices' => [],
                ],
                [
                    'key' => 'slug', 'type' => 'slug', 'label' => 'スラッグ',
                    'required' => true, 'max' => 120, 'choices' => [],
                ],
                [
                    'key' => 'answer', 'type' => 'richtext', 'label' => '回答',
                    'required' => false, 'choices' => [],
                ],
                [
                    'key' => 'status', 'type' => 'status', 'label' => 'ステータス',
                    'required' => true, 'choices' => [],
                ],
            ],
            'list' => ['title', 'status'],
            'search' => ['title'],
        ], $overrides);
    }

    /**
     * 作成をやりきる（確認 → 適用）。
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed> 適用に使った payload
     */
    protected function createContentType(User $user, array $overrides = []): array
    {
        $payload = $this->payload($overrides);

        $preview = $this->actingAs($user)->post('/admin/content-types', $payload);
        $preview->assertRedirect();

        $token = $this->flashedConfirmationToken();
        $this->assertNotNull($token, '確認トークンが返っていません');

        $this->actingAs($user)
            ->post('/admin/content-types', [...$payload, 'confirmation_token' => $token])
            ->assertRedirect('/admin/content-types');

        return $payload;
    }

    /** 直前のレスポンスが flash した確認トークン（Inertia の flash 領域） */
    protected function flashedConfirmationToken(): ?string
    {
        $confirmation = $this->inertiaFlash()['confirmation'] ?? null;

        return is_array($confirmation) && is_string($confirmation['token'] ?? null)
            ? $confirmation['token']
            : null;
    }

    /**
     * `Inertia::flash()` が積んだ値（通常の session flash とは別領域）。
     *
     * @return array<string, mixed>
     */
    protected function inertiaFlash(): array
    {
        /** @var array<string, mixed> $flash */
        $flash = session()->get(SessionKey::FLASH_DATA, []);

        return $flash;
    }

    /**
     * 入力が拒否され、**ファイルも DB も変わらない**ことを確かめる（HTTP 経路）。
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function assertRejected(array $overrides, string $errorKey): void
    {
        $before = $this->sandboxFingerprint();

        $this->actingAs($this->admin())
            ->from('/admin/content-types/create')
            ->post('/admin/content-types', $this->payload($overrides))
            ->assertRedirect('/admin/content-types/create')
            ->assertSessionHasErrors($errorKey);

        $this->assertSame($before, $this->sandboxFingerprint(), '拒否された入力でファイルが変わっています');
        $this->assertFalse(Schema::hasTable($this->typeId));
    }

    /**
     * 入力が **Application 層で** 拒否されることを確かめる。
     *
     * HTTP 経路には `TrimStrings` などの正規化が挟まるため、
     * 「前後の空白」「NUL」のような入力は web からは届かない。
     * それでも **ドメイン側が単独で拒否できる**ことを固定しておく
     * （middleware の設定に安全性を依存させない）。
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function assertDraftRejected(array $overrides, string $errorKey): void
    {
        $parsed = ContentTypeDraft::fromInput($this->payload($overrides));

        $this->assertNull($parsed['draft'], '受理されるべきではない入力が通りました');
        $this->assertArrayHasKey($errorKey, $parsed['errors']);
    }

    /** sandbox 内のファイル構成と内容のハッシュ */
    protected function sandboxFingerprint(): string
    {
        $parts = [];

        foreach ([$this->schemaPath, $this->modulesPath] as $root) {
            foreach (self::filesIn($root) as $file) {
                $parts[] = $file->getPathname().':'.md5_file($file->getPathname());
            }
        }

        sort($parts);

        return hash('sha256', implode("\n", $parts));
    }

    /** sandbox に何も残っていないこと（rollback の検証に使う） */
    protected function assertNoArtifacts(): void
    {
        $this->assertFileDoesNotExist("{$this->schemaPath}/{$this->typeId}.json", 'schema ファイルが残っています');
        $this->assertDirectoryDoesNotExist("{$this->modulesPath}/{$this->typeStudly}", 'モジュールが残っています');
        $this->assertFalse(
            Schema::hasTable($this->typeId),
            "テーブル [{$this->typeId}] が残っています",
        );
    }

    /** アプリを作り直して「次のリクエスト」を再現する（route / registry の再登録） */
    protected function rebootApplication(): void
    {
        $this->refreshApplication();
    }

    private function setEnv(string $key, string $value): void
    {
        $this->originalEnv[$key] = getenv($key);

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    /**
     * 実プロジェクトの schema / modules の内容ハッシュ。
     *
     * ファイル数・パス・中身をすべて含めるので、追加・削除・書き換えの
     * どれが起きても値が変わる。
     */
    private function projectFingerprint(): string
    {
        $parts = [];

        foreach (['content/schemas', 'modules'] as $relative) {
            $root = self::projectPath($relative);

            foreach (self::filesIn($root) as $file) {
                $parts[] = substr($file->getPathname(), strlen($root)).':'.md5_file($file->getPathname());
            }
        }

        sort($parts);

        return hash('sha256', implode("\n", $parts));
    }

    private static function projectPath(string $relative): string
    {
        return (string) realpath(__DIR__.'/../../../'.$relative);
    }

    /** @return iterable<SplFileInfo> */
    private static function filesIn(string $root): iterable
    {
        if (! is_dir($root)) {
            return [];
        }

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                yield $entry;
            }
        }
    }

    private static function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0o755, recursive: true) && ! is_dir($path)) {
            throw new \RuntimeException("一時ディレクトリを作成できません: {$path}");
        }
    }

    private static function copyTree(string $from, string $to): void
    {
        foreach (self::filesIn($from) as $file) {
            $target = $to.substr($file->getPathname(), strlen($from));
            self::makeDirectory(dirname($target));
            copy($file->getPathname(), $target);
        }
    }

    private static function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($path);
    }

    /**
     * sandbox 側の `Modules\<Name>\...` を読めるようにする。
     *
     * 本番では composer の PSR-4（`Modules\` → `modules/`）が同じ役目を果たす。
     * テストでは書き込み先が一時ディレクトリなので、その分だけ補う。
     * **同名クラスの再定義はしない**（生成されるクラス本体は ID から決まるので、
     * 一度読み込めば以降のテストでも同じ定義でよい）。
     */
    private static function registerSandboxAutoloader(): void
    {
        if (self::$autoloaderRegistered) {
            return;
        }

        self::$autoloaderRegistered = true;

        spl_autoload_register(static function (string $class): void {
            $root = self::$autoloadRoot;

            if ($root === null || ! str_starts_with($class, 'Modules\\') || class_exists($class, false)) {
                return;
            }

            $file = $root.'/'.str_replace('\\', '/', substr($class, strlen('Modules\\'))).'.php';

            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
