import { spawnSync } from "node:child_process";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { expect, it } from "vitest";

import { entryStubPhp } from "../src/deploy/release.js";

// PHPはホストに入れず、明示された既存Dockerイメージで常駐HTTPプロセスを検証する。
// 通常のCLI gateでは未実行をskipとして表示し、リリース検証で必ず有効化する。
const image = process.env.AGENTCMS_DEPLOY_PHP_IMAGE;

it.skipIf(!image)("常駐PHPで公開先をA→B→Aへ切り替えると次のHTTPから反映する", () => {
  const root = mkdtempSync(join(tmpdir(), "sgk-entry-cache-"));
  try {
    mkdirSync(join(root, "public"));
    writeFileSync(join(root, "public/index.php"), entryStubPhp("/qa/app"));
    for (const version of ["A", "B"]) {
      const release = join(root, "app/releases", version);
      mkdirSync(join(release, "vendor"), { recursive: true });
      mkdirSync(join(release, "bootstrap"));
      // Laravel境界だけを小さなfixtureにし、製品のentry stubとPHPパス解決はそのまま通す。
      writeFileSync(join(release, "vendor/autoload.php"), `<?php
namespace Illuminate\\Http { class Request { public static function capture() { return null; } } }
namespace Illuminate\\Foundation { class Application {
public function __construct(private string $version) {}
public function usePublicPath($path) {}
public function handleRequest($request) { echo $this->version; }
} }
`);
      writeFileSync(join(release, "bootstrap/app.php"), `<?php return new \\Illuminate\\Foundation\\Application('${version}');`);
    }
    const script = `set -eu
ln -s /qa/app/releases/A /qa/app/current
php -d realpath_cache_ttl=600 -S 127.0.0.1:8099 -t /qa/public /qa/public/index.php >/tmp/server.log 2>&1 &
server=$!
trap 'kill "$server" 2>/dev/null || true' EXIT
n=0
until curl --max-time 2 -fsS http://127.0.0.1:8099/ >/tmp/response; do
 n=$((n+1)); test "$n" -lt 30; sleep 0.1
done
for version in A B A; do
 ln -sfn "/qa/app/releases/$version" /qa/app/current
 response=$(curl --max-time 5 -fsS http://127.0.0.1:8099/)
 test "$response" = "$version" || { echo "expected=$version actual=$response"; exit 1; }
 echo "release=$response"
done
`;
    const result = spawnSync("docker", ["run", "--rm", "--pull", "never", "--network", "none", "--mount", `type=bind,src=${root},dst=/qa`, image!, "sh", "-c", script], { encoding: "utf8", timeout: 45_000 });
    expect(result.error).toBeUndefined();
    expect(result.status, result.stdout + result.stderr).toBe(0);
    expect(result.stdout).toBe("release=A\nrelease=B\nrelease=A\n");
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
}, 60_000);
