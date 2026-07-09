<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Process\ProcessRunner;

/**
 * Canned-response ProcessRunner: ordered first-match-wins substring rules, each holding a queue
 * of responses (the last response repeats once the queue is exhausted). Unmatched commands
 * succeed silently with [0, ''] so incidental sync/umount/partprobe/udevadm calls need no rules.
 */
final class FakeProcessRunner implements ProcessRunner
{
    /** @var list<string> every command line run, for assertions */
    public array $commands = [];

    /** @var list<array{substring: string, responses: list<array{int, string, string}>}> */
    private array $rules = [];

    public function on(string $substring, int $exit, string $stdout = '', string $stderr = ''): self
    {
        foreach ($this->rules as &$rule) {
            if ($rule['substring'] === $substring) {
                $rule['responses'][] = [$exit, $stdout, $stderr];

                return $this;
            }
        }
        unset($rule);
        $this->rules[] = ['substring' => $substring, 'responses' => [[$exit, $stdout, $stderr]]];

        return $this;
    }

    public function ran(string $substring): bool
    {
        foreach ($this->commands as $cmd) {
            if (str_contains($cmd, $substring)) {
                return true;
            }
        }

        return false;
    }

    public function run(
        string $cmd,
        ?callable $onStdout = null,
        ?callable $onStderr = null,
        bool $inheritStdin = false
    ): array {
        $this->commands[] = $cmd;

        $response = [0, '', ''];
        foreach ($this->rules as &$rule) {
            if (str_contains($cmd, $rule['substring'])) {
                $response = count($rule['responses']) > 1
                    ? array_shift($rule['responses'])
                    : $rule['responses'][0];
                break;
            }
        }
        unset($rule);
        [$exit, $stdout, $stderr] = $response;

        if ($stdout !== '' && $onStdout !== null) {
            $onStdout($stdout);
        }
        if ($stderr !== '' && $onStderr !== null) {
            $onStderr($stderr);
        }

        return [$exit, $stdout];
    }
}
