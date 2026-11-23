<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Concerns\SpeaksJson;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * stdin から JSON を受け、stdout へ JSON 1 行を返すコマンドの作法は **1 か所**（D-7）。
 *
 * ここで固定するのは 2 つ:
 *
 * 1. **同じ仕事をする実装が 2 つ以上ない**
 * 2. **書き出せなかったときの返事が揃っている**
 *
 * ## なぜ見るのか
 *
 * 🔴 **6 つのコマンドが同じ作法を別々に持っていて、既に答えが違っていた**
 * （2026-08-28 実測）:
 *
 * ```
 * emit()       2 通り — 書き出せないとき `{"status":"conflict"}` と `{"status":"error"}`
 * readStdin()  3 通り — 1 つだけ `StreamableInputInterface` を見ない
 * ```
 *
 * **呼ぶ側は JSON を読む。**status が違えば、**同じ失敗を別の失敗として扱う。**
 * しかも**どちらも「動いて」いる**ので、片方だけ直しても気づけない。
 *
 * ▷ `readStdin()` の違いはもっと直接的で、**`StreamableInputInterface` を見ない実装は
 * 試験から入力を流し込めない**。つまり**その口だけ、試験が書けない。**
 */
final class JsonCommandContractTest extends TestCase
{
    /** stdin から JSON を受け取るコマンド */
    private const COMMANDS = [
        'CreateContentTypeCommand.php',
        'ImportFormCommand.php',
        'ImportMenuCommand.php',
        'ImportPageCommand.php',
        'ImportTermsCommand.php',
        'OperationCommand.php',
    ];

    public function test_同じ作法を持つ実装が_2_つ以上ない(): void
    {
        $offenders = [];

        foreach (self::COMMANDS as $file) {
            $body = (string) file_get_contents(app_path('Console/Commands/'.$file));

            foreach (['emit', 'readStdin', 'refuse'] as $name) {
                if (preg_match("/private function {$name}\\(/", $body) === 1) {
                    $offenders[] = "{$file} → {$name}()";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "同じ作法を自前で持っている:\n  ".implode("\n  ", $offenders),
        );
    }

    public function test_書き出せなかったときは_error_と言う(): void
    {
        /*
         * 🔴 **呼ぶ側は JSON を読む。**
         *
         * `conflict` と `error` に割れていたが、**JSON にできなかったのは衝突ではない**。
         * 呼ぶ側が「衝突が起きた」と読むと、**やり直せば直ると思ってしまう。**
         *
         * ▷ **実際に書き出させて確かめる。**綴りを数えるだけだと、
         *   1 か所に寄せた時点で**何を言っているかを見なくなる**
         *   （寄せた直後、変異がすり抜けた）。
         */
        $command = new class extends Command
        {
            use SpeaksJson;

            /** JSON にできないもの（未定義の文字列）を渡して失敗させる */
            public function handle(): int
            {
                return $this->emit(['bad' => "\xB1\x31"], failure: true);
            }
        };

        $command->setLaravel(app());

        $output = new BufferedOutput;
        $exit = $command->run(new ArrayInput([]), $output);

        $this->assertSame(1, $exit, '失敗として返っていない');
        $this->assertSame(
            ['status' => 'error'],
            json_decode(trim($output->fetch()), true),
        );
    }

    public function test_断るときは_validation_error_と言い_何もしないと言う(): void
    {
        /*
         * 🔴 **1 つに寄せた時点で、何を言っているかを見なくなる。**
         * 綴りを数える番人だけだと、寄せたあとに中身が変わっても通ってしまう
         * （emit のときに実際にすり抜けた）。**実際に断らせて読む。**
         *
         * 呼ぶ側は `status` で分岐する。`validation_error` は
         * **渡したものが悪いので、やり直しても同じ**という意味。
         * `action: none` は**何も書いていない**という意味。
         * ここが変わると、呼ぶ側が「やり直せば直る」と思ってしまう。
         */
        $command = new class extends Command
        {
            use SpeaksJson;

            protected $signature = 'test:refuse';

            public function handle(): int
            {
                return $this->refuse('うけとれない');
            }
        };

        $command->setLaravel(app());
        $output = new BufferedOutput;
        $code = $command->run(new ArrayInput([]), $output);

        $this->assertSame(1, $code, '断ったのに成功として返っている');

        $payload = json_decode($output->fetch(), true);
        $this->assertSame('validation_error', $payload['status'] ?? null);
        $this->assertSame('none', $payload['action'] ?? null);
        $this->assertSame('うけとれない', $payload['message'] ?? null);
    }
}
