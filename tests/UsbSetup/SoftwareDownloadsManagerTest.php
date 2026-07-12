<?php

declare(strict_types=1);

namespace App\Tests\UsbSetup;

use App\UsbSetup\SoftwareDownloadsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SoftwareDownloadsManagerTest extends TestCase
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
        // Stub filesystem probes: only "config/soft" is a dir, only "config/file.exe" is a file.
        $isDir = static fn(string $p): bool => $p === 'config/soft';
        $isFile = static fn(string $p): bool => $p === 'config/file.exe';

        self::assertSame($expected, SoftwareDownloadsManager::classifyDownloadLine($line, $isFile, $isDir));
    }

    public function testClassifyDownloadLineRecursiveSuffixButNotADirIsInvalid(): void
    {
        $false = static fn(string $p): bool => false;

        $result = SoftwareDownloadsManager::classifyDownloadLine('missing/**', $false, $false);

        self::assertSame('invalid', $result['type']);
    }

    public function testParseLastContentTypeNoneReturnsNull(): void
    {
        self::assertNull(
            SoftwareDownloadsManager::parseLastContentType(['HTTP/1.1 200 OK', 'Content-Length: 10'])
        );
    }

    public function testParseLastContentTypeReturnsFinalMatch(): void
    {
        // Simulates curl -D across a redirect: first response text/html, final application/octet-stream.
        $headers = [
            'HTTP/1.1 302 Found',
            'Content-Type: text/html; charset=utf-8',
            'Location: https://cdn.example.com/a.exe',
            'HTTP/1.1 200 OK',
            'Content-Type: application/octet-stream',
            'Content-Length: 12345',
        ];

        self::assertSame('application/octet-stream', SoftwareDownloadsManager::parseLastContentType($headers));
    }

    #[DataProvider('shouldRejectAsHtmlProvider')]
    public function testShouldRejectAsHtml(?string $contentType, bool $expected): void
    {
        self::assertSame($expected, SoftwareDownloadsManager::shouldRejectAsHtml($contentType));
    }
}
