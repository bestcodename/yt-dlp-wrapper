<?php

declare(strict_types=1);

namespace App\Process;

use RuntimeException;

final class ProcOpenProcessRunner implements ProcessRunner
{
    public function run(
        string $cmd,
        ?callable $onStdout = null,
        ?callable $onStderr = null,
        bool $inheritStdin = false
    ): array {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        if (!$inheritStdin) {
            $descriptors[0] = ['pipe', 'r'];
        }
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException("Failed to start: {$cmd}");
        }
        if (!$inheritStdin) {
            fclose($pipes[0]);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        while (true) {
            $status = proc_get_status($proc);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            if ($out !== false && $out !== '') {
                $stdout .= $out;
                if ($onStdout !== null) {
                    $onStdout($out);
                }
            }
            if ($err !== false && $err !== '' && $onStderr !== null) {
                $onStderr($err);
            }
            if (!$status['running']) {
                break;
            }
            usleep(50000);
        }
        foreach ([0 => false, 1 => true] as $pipe => $isErr) {
            $chunk = stream_get_contents($pipes[$pipe + 1]);
            if ($chunk) {
                if (!$isErr) {
                    $stdout .= $chunk;
                    if ($onStdout !== null) {
                        $onStdout($chunk);
                    }
                } elseif ($onStderr !== null) {
                    $onStderr($chunk);
                }
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [$exit, $stdout];
    }
}
