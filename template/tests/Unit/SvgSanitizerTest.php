<?php

declare(strict_types=1);

namespace Tests\Unit;

use AgentCms\Core\Uploads\SvgSanitizer;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SVG サニタイズの回帰 fixture（Phase 8.5-F / F-UX5）。
 *
 * SVG は能動的コンテンツを含み得るため、**保存されるのは常に再構築後の内容**。
 * ここでは「実行され得る payload が残らない」ことを固定する。
 */
final class SvgSanitizerTest extends TestCase
{
    private SvgSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new SvgSanitizer;
    }

    /** @return list<array{string, string}> */
    public static function maliciousPayloads(): array
    {
        $svg = fn (string $inner, string $attrs = ''): string => '<svg xmlns="http://www.w3.org/2000/svg" '
            ."viewBox=\"0 0 10 10\"{$attrs}>{$inner}</svg>";

        return [
            'script element' => [$svg('<script>alert(1)</script><rect width="10" height="10"/>'), 'alert(1)'],
            'onload handler' => [$svg('<rect width="10" height="10"/>', ' onload="alert(1)"'), 'onload'],
            'onclick on child' => [$svg('<rect width="10" height="10" onclick="alert(1)"/>'), 'onclick'],
            'javascript href' => [$svg('<a href="javascript:alert(1)"><rect width="10" height="10"/></a>'), 'javascript:'],
            'foreignObject html' => [$svg('<foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><img src=x onerror=alert(1)></body></foreignObject>'), 'onerror'],
            'external use' => [$svg('<use href="https://evil.example/x.svg#a"/>'), 'evil.example'],
            'external image' => [$svg('<image href="http://evil.example/x.png"/>'), 'evil.example'],
            'style with url' => [$svg('<rect width="10" height="10" style="background:url(http://evil.example/x)"/>'), 'evil.example'],
            'animate set handler' => [$svg('<set attributeName="onload" to="alert(1)"/>'), 'alert(1)'],
            'entity encoded javascript' => [$svg('<a href="java&#115;cript:alert(1)"><rect width="10" height="10"/></a>'), 'alert(1)'],
        ];
    }

    /**
     * 危険な payload は **REJECT されるか、無害化されて保存される**かのどちらか。
     * どちらでも「実行され得る内容が保存されない」という要件は満たす。
     */
    #[DataProvider('maliciousPayloads')]
    public function test_dangerous_payloads_do_not_survive(string $payload, string $needle): void
    {
        try {
            $result = $this->sanitizer->sanitize($payload);
        } catch (ValidationException) {
            // REJECT も許容される結果（保存されない）
            $this->assertTrue(true);

            return;
        }

        $this->assertStringNotContainsStringIgnoringCase(
            $needle,
            $result['content'],
            "危険な payload が残っています: {$needle}",
        );
        $this->assertStringNotContainsStringIgnoringCase('<script', $result['content']);
        $this->assertStringNotContainsStringIgnoringCase('<foreignObject', $result['content']);
    }

    public function test_doctype_and_entities_are_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->sanitizer->sanitize(
            '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>',
        );
    }

    public function test_non_svg_root_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->sanitizer->sanitize('<html><body>hello</body></html>');
    }

    public function test_broken_xml_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->sanitizer->sanitize('<svg><rect></svg>');
    }

    public function test_safe_svg_is_preserved(): void
    {
        $result = $this->sanitizer->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">'
            .'<path fill="currentColor" d="M11 22V9.5h2V22z"/>'
            .'<circle cx="12" cy="6" r="3" stroke="#000" stroke-width="2"/></svg>',
        );

        $this->assertStringContainsString('<path', $result['content']);
        $this->assertStringContainsString('d="M11 22V9.5h2V22z"', $result['content']);
        $this->assertStringContainsString('<circle', $result['content']);
        $this->assertSame(24, $result['width']);
        $this->assertSame(24, $result['height']);
    }

    public function test_dimensions_fall_back_to_view_box(): void
    {
        $result = $this->sanitizer->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 60"><rect width="120" height="60"/></svg>',
        );

        $this->assertSame(120, $result['width']);
        $this->assertSame(60, $result['height']);
    }

    public function test_internal_fragment_reference_is_kept(): void
    {
        $result = $this->sanitizer->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
            .'<defs><linearGradient id="g"><stop offset="0" stop-color="#000"/></linearGradient></defs>'
            .'<rect width="10" height="10" fill="url(#g)"/></svg>',
        );

        $this->assertStringContainsString('url(#g)', $result['content']);
        $this->assertStringContainsString('linearGradient', $result['content']);
    }
}
