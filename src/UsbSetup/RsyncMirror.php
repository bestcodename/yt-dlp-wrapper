<?php

declare(strict_types=1);

namespace App\UsbSetup;

use App\Console\PromptHint;
use App\Process\ProcessRunner;
use RuntimeException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

final class RsyncMirror
{
    /** Windows/desktop-trash/vfat-fsck artifacts never worth mirroring between sticks. */
    private const RSYNC_EXCLUDES = ['System Volume Information', '.Trash-*', '.Trashes', 'FOUND.[0-9][0-9][0-9]'];

    public function __construct(private readonly ProcessRunner $runner)
    {
    }

    /**
     * Mirrors the source stick's data partition onto the target with rsync. With $withDelete a
     * dry run first previews the changes and asks for confirmation when files would be deleted
     * from the target. Returns false when the user declines.
     */
    public function __invoke(
        string $srcMount,
        string $dstMount,
        bool $withDelete,
        bool $skipConfirm,
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
        SymfonyStyle $io,
    ): bool {
        if ($withDelete) {
            $io->text('Computing changes (rsync dry run)...');
            [$exit, $out] = $this->runCmd(self::buildRsyncCommand($srcMount, $dstMount, true, true));
            if ($exit !== 0) {
                throw new RuntimeException("rsync dry run failed (exit $exit).");
            }
            $summary = self::summarizeRsyncItemized($out);
            $io->text(
                sprintf(
                    'Mirror will add %d, change %d and delete %d item(s).',
                    count($summary['added']),
                    count($summary['changed']),
                    count($summary['deleted'])
                )
            );
            if ($summary['deleted'] !== []) {
                $lines = array_slice($summary['deleted'], 0, 20);
                if (count($summary['deleted']) > 20) {
                    $lines[] = sprintf('... and %d more', count($summary['deleted']) - 20);
                }
                $io->warning(
                    array_merge(
                        ['These files/directories exist only on the target and will be DELETED:'],
                        $lines
                    )
                );
                if (!$skipConfirm) {
                    $q = new ConfirmationQuestion(
                        'Proceed with mirror (including deletions)? [yes/NO] '.PromptHint::yesFlag().' ',
                        false,
                        '/^yes$/i'
                    );
                    if (!$helper->ask($input, $output, $q)) {
                        return false;
                    }
                }
            }
        }

        $io->text('Mirroring data partition from source stick...');
        [$exit] = $this->runCmd(self::buildRsyncCommand($srcMount, $dstMount, $withDelete, false), true, $output);
        if ($exit !== 0) {
            throw new RuntimeException("rsync failed (exit $exit) while mirroring the data partition.");
        }
        $io->text('Data partition mirrored.');

        return true;
    }

    private function runCmd(string $cmd, bool $passthru = false, ?OutputInterface $output = null): array
    {
        return $this->runner->run(
            $cmd,
            ($passthru && $output !== null) ? static fn(string $chunk) => $output->write($chunk) : null,
            $output !== null ? static fn(string $chunk) => $output->getErrorOutput()->write($chunk) : null,
        );
    }

    /**
     * Builds the rsync command line for the stick-to-stick mirror. -rt instead of -a because vfat
     * has no owner/permission/symlink support; --modify-window=1 because FAT stores mtimes with
     * 2-second granularity (without it every file would re-copy on --update); --inplace so a
     * changed multi-GiB persistence.dat does not need temp+old copies simultaneously on a nearly
     * full stick; --max-size matches the FAT32 single-file limit enforced elsewhere. Pure —
     * unit-testable.
     */
    public static function buildRsyncCommand(string $srcMount, string $dstMount, bool $delete, bool $dryRun): string
    {
        $cmd = 'rsync -rt --modify-window=1 --max-size=4090m';
        foreach (self::RSYNC_EXCLUDES as $exclude) {
            $cmd .= ' --exclude='.escapeshellarg($exclude);
        }
        if ($delete) {
            $cmd .= ' --delete';
        }
        if ($dryRun) {
            $cmd .= ' --dry-run --itemize-changes';
        } else {
            // --outbuf=L line-flushes --info=progress2 through the (non-tty) pipe to runCmd
            $cmd .= ' --inplace --outbuf=L --info=progress2';
        }

        return $cmd.' '.escapeshellarg(rtrim($srcMount, '/').'/').' '.escapeshellarg(rtrim($dstMount, '/').'/');
    }

    /**
     * Buckets rsync --dry-run --itemize-changes output into deleted/added/changed paths. Pure —
     * unit-testable.
     *
     * @return array{deleted: string[], added: string[], changed: string[]}
     */
    public static function summarizeRsyncItemized(string $out): array
    {
        $result = ['deleted' => [], 'added' => [], 'changed' => []];
        foreach (preg_split('/\r?\n/', $out) ?: [] as $line) {
            if (preg_match('/^\*deleting\s+(.+)$/', $line, $m)) {
                $result['deleted'][] = $m[1];
            } elseif (preg_match('/^cd\+{9}\s+(.+)$/', $line, $m)) {
                $result['added'][] = $m[1];
            } elseif (preg_match('/^>f(\S{9})\s+(.+)$/', $line, $m)) {
                $result[$m[1] === '+++++++++' ? 'added' : 'changed'][] = $m[2];
            }
        }

        return $result;
    }
}
