<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Media;

/**
 * 取得先が繋いでよい相手かを決める（Phase 9.14 / STAGE 27E-3・SPEC §C.6）。
 *
 * ## ホスト名では判定しない。**解決した IP で判定する**
 *
 * `http://internal.example.com/` は名前だけ見れば公開のホストに見えるが、
 * 名前解決が `10.0.0.1` を返すなら社内へ繋ぐ。名前で弾く検査は、
 * **DNS の答えを変えるだけで通り抜けられる**。
 *
 * ## 判定した IP へ接続を固定する
 *
 * 判定と接続の間で名前解決をやり直すと、その隙に別の答えを返せる
 * （DNS rebinding）。だから `allow()` は**繋ぐべき IP を返し**、
 * 呼ぶ側はその IP へ固定して繋ぐ。**判定の結果と接続先が同じ物**でなければ、
 * 判定は何も守っていない。
 *
 * ## 通す方を狭くする
 *
 * 「危ない範囲を並べて弾く」形は、並べ忘れが即座に穴になる。
 * ここでは PHP の `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE` を土台に、
 * **それが見ない範囲を足して**弾く（loopback は `NO_RES_RANGE` に入るが、
 * CGNAT・IPv4-mapped IPv6 は入らない）。
 */
final class AddressGuard
{
    /**
     * IPv6 で**通してよい範囲**（global unicast）。
     *
     * ## 危ない書き方は、数え上げられない
     *
     * IPv4 は IPv6 の中へ何通りにも埋められる — `::ffff:` / `::` の IPv4 互換 /
     * NAT64 / 6to4 / Teredo。しかも **NAT64 の prefix は運用者が選べる**（RFC 8215）ので、
     * 「危ない prefix を並べる」形は**原理的に挙げ切れない**（F-13b）。
     *
     * だから**通す側を狭める**。`2000::/3` の外は、それが何であれ拒否する。
     * loopback・unique-local・link-local・IPv4 互換・NAT64 は**すべてこの外側**なので、
     * 個別に並べる必要が無くなった。
     *
     * ## 代わりに、**塞ぎすぎる側の失敗が増える**
     *
     * deny-list は「通してはいけないものを通す」方へ間違う。allow-list はその逆で、
     * **通すべきものを塞ぐ**方へ間違う。そして**塞いだときは静かである** —
     * 例外も警告も出ず、利用者には「その画像だけ移行できなかった」としか見えない（§C.6）。
     *
     * だから範囲を書き換えるときは、**通り続けるべきアドレスの側も**試験に足すこと。
     * `2001::/32`（Teredo）を `2001::/16` と書き間違えても、
     * **公開アドレスの試験が無ければ誰も気づかない。**
     */
    private const IPV6_ALLOWED = '2000::/3';

    /**
     * 通してよい範囲の**中に在るのに、中身が IPv4 である**もの。
     *
     * `2000::/3` に入っていても、これらは別のアドレスを包んでいる。
     * **allow-list を通り抜けるものだけをここへ置く** — 外側のものを並べると、
     * どちらの規則が効いているのか読めなくなる。
     */
    private const EMBEDDED_IN_ALLOWED = [
        // 6to4。`2002:a00:1::1` の 16-48 bit が 10.0.0.1 である
        '2002::/16',
        // Teredo。`2001:0:…` は中に IPv4 を 2 つ持つ
        '2001:0::/32',
    ];

    /**
     * IPv4 で `FILTER_FLAG_NO_RES_RANGE` が見ない範囲。
     *
     * ## 検討して、足さなかったもの
     *
     * **並べ忘れと区別できるように書いておく。** `224.0.0.0/4`（multicast）・
     * `192.0.0.0/24`（IETF プロトコル割り当て）・`198.18.0.0/15`（ベンチマーク）は
     * **HTTP で内部へ届く経路ではない**ので足していない。
     */
    private const EXTRA_DENIED = [
        // CGNAT。共有アドレス空間で、事業者の内側へ届く
        '100.64.0.0/10',
    ];

    /**
     * @param  bool  $allowPrivate  **試験のためだけの逃げ道**（既定は塞いだまま）。
     *
     * `true` にすると内部アドレスへも繋ぐ。**本番の経路からは決して渡さない** —
     * `AddressGuardSeamTest` が「`true` を渡している場所は試験だけ」を固定している。
     *
     * この穴を開けたのは、**取得の守りを本物の curl で当てるため**である。
     * 組み込みサーバーは `127.0.0.1` にしか立たないので、ここを通れないと
     * 「curl の指定が効くか」と「利用者に何が返るか」が**別々の試験に分かれたまま**になり、
     * 実際そのせいで「上限超過が `TooLarge` にならない」を取り逃した（F-21）。
     */
    public function __construct(
        private readonly HostResolver $resolver,
        private readonly bool $allowPrivate = false,
    ) {}

    /**
     * 繋いでよければ**固定する IP**、駄目なら理由。
     *
     * @return array{0: string|null, 1: MediaFailure|null}
     */
    public function allow(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'], $parts['scheme'])) {
            return [null, MediaFailure::Scheme];
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            // http/https だけ（§C.6）。`file://` `data:` `gopher://` は入口ごと無い
            return [null, MediaFailure::Scheme];
        }

        $addresses = $this->resolver->resolve($parts['host']);

        if ($addresses === []) {
            return [null, MediaFailure::Fetch];
        }

        /*
         * **1 つでも駄目なら全部駄目。** 「良い IP が 1 つあればそこへ繋ぐ」形は、
         * 公開 IP と内部 IP を同じ名前に並べるだけで内部へ繋げる余地を残す
         * （どちらへ繋ぐかは実装の都合で決まってしまう）。
         */
        foreach ($addresses as $address) {
            if (! $this->allowPrivate && ! $this->publicAddress($address)) {
                return [null, MediaFailure::Address];
            }
        }

        return [$addresses[0], null];
    }

    private function publicAddress(string $address): bool
    {
        /*
         * **先に剥がす。** `::ffff:` の形は IPv4 そのものなので、
         * IPv4 の規則で見る（公開アドレスなら通り、private なら落ちる）。
         */
        $normalised = $this->unmapped($address);

        if (filter_var($normalised, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($normalised, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $this->publicIpv6($normalised);
        }

        foreach (self::EXTRA_DENIED as $cidr) {
            if ($this->inRange($normalised, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * IPv6 は**通す側を挙げて**判定する（F-13b）。
     *
     * `2000::/3` の外は理由を問わず拒否する。中に在るものだけ、
     * IPv4 を包んでいる形（6to4 / Teredo）を個別に弾く。
     */
    private function publicIpv6(string $address): bool
    {
        if (! $this->inRange($address, self::IPV6_ALLOWED)) {
            return false;
        }

        foreach (self::EMBEDDED_IN_ALLOWED as $cidr) {
            if ($this->inRange($address, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `::ffff:10.0.0.1` のような IPv4-mapped を IPv4 として見る。
     *
     * **書き方を変えただけで検査を抜けられては意味が無い。**
     */
    private function unmapped(string $address): string
    {
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $found) === 1) {
            return $found[1];
        }

        return $address;
    }

    private function inRange(string $address, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ip = @inet_pton($address);
        $net = @inet_pton($subnet);

        if ($ip === false || $net === false || strlen($ip) !== strlen($net)) {
            return false;
        }

        $bytes = intdiv((int) $bits, 8);
        $remainder = (int) $bits % 8;

        if (substr($ip, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($ip[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}
