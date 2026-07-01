<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\UsbSetupCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class UsbSetupCommandTest extends TestCase
{
    /**
     * @return array<string, array{string, array{type: string, path: string, recursive: bool}}>
     */
    public static function classifyDownloadLineProvider(): array
    {
        return [
            'http' => [
                'http://example.com/a.exe',
                ['type' => 'http', 'path' => 'http://example.com/a.exe', 'recursive' => false],
            ],
            'https case-insensitive' => [
                'HTTPS://example.com/a.exe',
                ['type' => 'http', 'path' => 'HTTPS://example.com/a.exe', 'recursive' => false],
            ],
            'magnet' => [
                'magnet:?xt=urn:btih:abc',
                ['type' => 'torrent', 'path' => 'magnet:?xt=urn:btih:abc', 'recursive' => false],
            ],
            'urn:btmh' => [
                'urn:btmh:1220abc',
                ['type' => 'torrent', 'path' => 'urn:btmh:1220abc', 'recursive' => false],
            ],
            'recursive local dir' => [
                'config/soft/**',
                ['type' => 'local', 'path' => 'config/soft', 'recursive' => true],
            ],
            'plain local' => [
                'config/file.exe',
                ['type' => 'local', 'path' => 'config/file.exe', 'recursive' => false],
            ],
            'invalid' => ['not-a-thing', ['type' => 'invalid', 'path' => 'not-a-thing', 'recursive' => false]],
        ];
    }

    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function parseChecksumProvider(): array
    {
        $sums = "abc123  debian-live-12-amd64-standard.iso\n"
            ."DEF456 *debian-live-12-amd64-cinnamon.iso\n"
            ."\n"
            ."# comment line\n";

        return [
            'space separator' => [$sums, 'debian-live-12-amd64-standard.iso', 'abc123'],
            'binary star marker + lowercased' => [$sums, 'debian-live-12-amd64-cinnamon.iso', 'def456'],
            'no match returns null' => [$sums, 'nonexistent.iso', null],
            'empty content returns null' => ['', 'anything.iso', null],
        ];
    }

    /**
     * @return array<string, array{?string, bool}>
     */
    public static function shouldRejectAsHtmlProvider(): array
    {
        return [
            'text/html rejected' => ['text/html; charset=utf-8', true],
            'text/plain rejected' => ['text/plain', true],
            'octet-stream kept' => ['application/octet-stream', false],
            'null kept' => [null, false],
        ];
    }

    /**
     * @param array{type: string, path: string, recursive: bool} $expected
     */
    #[DataProvider('classifyDownloadLineProvider')]
    public function testClassifyDownloadLine(string $line, array $expected): void
    {
        $method = new ReflectionMethod(UsbSetupCommand::class, 'classifyDownloadLine');

        // Stub filesystem probes: only "config/soft" is a dir, only "config/file.exe" is a file.
        $isDir = static fn(string $p): bool => $p === 'config/soft';
        $isFile = static fn(string $p): bool => $p === 'config/file.exe';

        self::assertSame($expected, $method->invoke(null, $line, $isFile, $isDir));
    }

    public function testClassifyDownloadLineRecursiveSuffixButNotADirIsInvalid(): void
    {
        $method = new ReflectionMethod(UsbSetupCommand::class, 'classifyDownloadLine');
        $false = static fn(string $p): bool => false;

        $result = $method->invoke(null, 'missing/**', $false, $false);

        self::assertSame('invalid', $result['type']);
    }

    #[DataProvider('parseChecksumProvider')]
    public function testParseChecksum(string $sums, string $filename, ?string $expected): void
    {
        $method = new ReflectionMethod(UsbSetupCommand::class, 'parseChecksum');

        self::assertSame($expected, $method->invoke(null, $sums, $filename));
    }

    public function testParseLastContentTypeNoneReturnsNull(): void
    {
        $method = new ReflectionMethod(UsbSetupCommand::class, 'parseLastContentType');

        self::assertNull($method->invoke(null, ['HTTP/1.1 200 OK', 'Content-Length: 10']));
    }

    public function testParseLastContentTypeReturnsFinalMatch(): void
    {
        $method = new ReflectionMethod(UsbSetupCommand::class, 'parseLastContentType');

        // Simulates curl -D across a redirect: first response text/html, final application/octet-stream.
        $headers = [
            'HTTP/1.1 302 Found',
            'Content-Type: text/html; charset=utf-8',
            'Location: https://cdn.example.com/a.exe',
            'HTTP/1.1 200 OK',
            'Content-Type: application/octet-stream',
            'Content-Length: 12345',
        ];

        self::assertSame('application/octet-stream', $method->invoke(null, $headers));
    }

    #[DataProvider('shouldRejectAsHtmlProvider')]
    public function testShouldRejectAsHtml(?string $contentType, bool $expected): void
    {
        $method = new ReflectionMethod(UsbSetupCommand::class, 'shouldRejectAsHtml');

        self::assertSame($expected, $method->invoke(null, $contentType));
    }
}
