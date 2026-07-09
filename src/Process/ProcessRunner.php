<?php

declare(strict_types=1);

namespace App\Process;

interface ProcessRunner
{
    /**
     * Runs a shell command line. $onStdout/$onStderr receive raw output chunks as they arrive.
     * $inheritStdin=false closes the child's stdin; true leaves it inherited from the caller
     * (needed for tools that probe their stdin, e.g. yt-dlp).
     *
     * @return array{0: int, 1: string} [exit code, accumulated stdout]
     */
    public function run(
        string $cmd,
        ?callable $onStdout = null,
        ?callable $onStderr = null,
        bool $inheritStdin = false
    ): array;
}
