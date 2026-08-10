<?php

namespace Tests\Concerns;

use App\Support\Logging\EvidenceLog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs a test under PRODUCTION's logging condition and reads back what was
 * actually written to disk.
 *
 * ## Why a spy is not enough here
 *
 * `Log::spy()` replaces the log manager, so it cannot see a LEVEL at all: a
 * `Log::info()` the production box discards is a `Log::info()` a spy records, and
 * this repository has now shipped that exact decoration twice. MEASURED on the
 * box: `LOG_CHANNEL=stack`, `LOG_STACK=single`, `LOG_LEVEL=warning`, so every
 * info line on the default channel is dropped before it reaches a file.
 *
 * This trait reproduces that condition (`warning` on the application channel)
 * and redirects the two PATHS into a temp directory. The LEVEL of
 * {@see EvidenceLog::CHANNEL} is deliberately NOT set: it is what is under test,
 * and it has to arrive from `config/logging.php` for the assertion to mean
 * anything.
 *
 * Under the system temp dir rather than `storage/logs`, so no test can append to
 * the log a developer or a CI runner is reading.
 */
trait CapturesEvidenceLog
{
    /**
     * The temp directory both log files are redirected into.
     */
    private ?string $capturedLogDirectory = null;

    /**
     * Redirect the application log and the evidence log into temp files, with
     * production's own `warning` level on the application channel.
     */
    protected function captureLogsUnderProductionLevels(): void
    {
        $directory = sys_get_temp_dir().'/uptizm-evidence-'.Str::random(12);

        File::ensureDirectoryExists($directory);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));

        $this->capturedLogDirectory = $directory;

        config([
            'logging.default' => 'stack',
            // Pinned rather than inherited: a developer running with
            // `LOG_STACK=daily` would otherwise send the control line to a path
            // this trait never redirected, which is both a polluted
            // `storage/logs` and an assertion over an empty file.
            'logging.channels.stack.channels' => ['single'],
            'logging.channels.single.level' => 'warning',
            'logging.channels.single.path' => $directory.'/application.log',
            'logging.channels.'.EvidenceLog::CHANNEL.'.path' => $directory.'/evidence.log',
        ]);

        // The manager caches a resolved channel, so a channel built before the
        // config above would keep the real path and the real level.
        Log::forgetChannel('stack');
        Log::forgetChannel('single');
        Log::forgetChannel(EvidenceLog::CHANNEL);
    }

    /**
     * Everything the evidence channel wrote during the test.
     */
    protected function evidenceLogContents(): string
    {
        return $this->capturedContents('evidence');
    }

    /**
     * Everything the application's default channel wrote during the test.
     */
    protected function applicationLogContents(): string
    {
        return $this->capturedContents('application');
    }

    /**
     * Read one redirected log back, whichever filename its driver chose.
     *
     * Globbed rather than named: the evidence channel dates its file (`daily`),
     * the application channel does not, and what is under test is the content.
     */
    private function capturedContents(string $stem): string
    {
        $this->assertNotNull(
            $this->capturedLogDirectory,
            'captureLogsUnderProductionLevels() was never called, so this reads an unredirected log.',
        );

        $files = glob($this->capturedLogDirectory.'/'.$stem.'*.log') ?: [];

        return implode('', array_map('file_get_contents', $files));
    }
}
