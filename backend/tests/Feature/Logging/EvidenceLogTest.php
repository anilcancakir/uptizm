<?php

namespace Tests\Feature\Logging;

use App\Support\Logging\EvidenceLog;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use Tests\Concerns\CapturesEvidenceLog;
use Tests\TestCase;

/**
 * The evidence channel itself: its level, its independence from `LOG_LEVEL`, and
 * that a line written to it actually reaches a file under the condition
 * production runs in.
 *
 * WHY THIS FILE EXISTS. Three lines in `app/` record why an operator was NOT
 * paged and who sent a credential where. All three were `Log::info()` on the
 * default channel, and production runs `LOG_LEVEL=warning`, so all three had
 * never once been written while tests asserting them passed against a faked
 * logger. Every other test of those three lines asserts CONTENT; this one
 * asserts the line can exist at all.
 */
class EvidenceLogTest extends TestCase
{
    use CapturesEvidenceLog;

    /**
     * The channel's level cannot be reached by `LOG_LEVEL`.
     *
     * The bug being fixed is a level inherited from a global knob, so hanging the
     * fix on a channel that reads the SAME knob would look fixed and behave
     * identically. This poisons `LOG_LEVEL` to production's value and re-reads
     * the config file: the assertion on `single` is what proves the poison took,
     * so the assertion on the evidence channel cannot pass vacuously.
     *
     * Both env arrays are written because `Env` reads `$_ENV` and `$_SERVER`
     * before it reaches `getenv()`, so `putenv()` alone would be a no-op against
     * a value that is already loaded, and this test would then certify itself.
     */
    public function test_the_evidence_channel_level_is_not_read_from_the_global_log_level(): void
    {
        $original = $this->currentEnvValue('LOG_LEVEL');

        $_ENV['LOG_LEVEL'] = 'warning';
        $_SERVER['LOG_LEVEL'] = 'warning';

        try {
            $channels = (require config_path('logging.php'))['channels'];
        } finally {
            $this->restoreEnvValue('LOG_LEVEL', $original);
        }

        // The poison took: the channel that reads the global knob moved.
        $this->assertSame('warning', $channels['single']['level']);

        // This one did not, and the claim is semantic rather than a string
        // compare: whatever it resolves to has to RECORD an info line.
        $this->assertTrue(
            Level::fromName($channels[EvidenceLog::CHANNEL]['level'])->includes(Level::Info),
            'The evidence channel resolved a level that discards the lines it exists to keep.',
        );
    }

    /**
     * An unset variable leaves the instrument RECORDING.
     *
     * A knob whose default is silence is the same bug wearing a different name,
     * and nothing in a deployment guarantees the variable is set: it is absent
     * from production's `.env` today, as `AI_ROUTING_LOG_LEVEL` also is.
     */
    public function test_the_evidence_channel_records_when_its_own_level_variable_is_unset(): void
    {
        $original = $this->currentEnvValue('EVIDENCE_LOG_LEVEL');

        unset($_ENV['EVIDENCE_LOG_LEVEL'], $_SERVER['EVIDENCE_LOG_LEVEL']);

        try {
            $channels = (require config_path('logging.php'))['channels'];
        } finally {
            $this->restoreEnvValue('EVIDENCE_LOG_LEVEL', $original);
        }

        $this->assertTrue(
            Level::fromName($channels[EvidenceLog::CHANNEL]['level'])->includes(Level::Info),
            'An unset EVIDENCE_LOG_LEVEL left the channel silent, which is the original bug renamed.',
        );
    }

    /**
     * THE END-TO-END CLAIM: a line survives production's own log level.
     *
     * Written through the real log manager, read back off a real file, with the
     * application channel pinned to `warning` exactly as the box runs it. The
     * control line is the anti-vacuity half: it proves this harness DOES drop an
     * info line on the default channel, so the evidence file being full is a
     * property of the channel rather than of the fixture.
     */
    public function test_an_evidence_line_survives_a_warning_level_application_log(): void
    {
        $this->captureLogsUnderProductionLevels();

        EvidenceLog::record('Something worth keeping happened.', ['team_id' => 'team_1']);

        Log::info('A control line the default channel must drop.');

        $written = $this->evidenceLogContents();

        $this->assertStringContainsString('Something worth keeping happened.', $written);
        $this->assertStringContainsString('"team_id":"team_1"', $written);

        // The application log holds neither line: not the control (its level
        // dropped it) and not the evidence line (it never went there).
        $application = $this->applicationLogContents();

        $this->assertStringNotContainsString('A control line the default channel must drop.', $application);
        $this->assertStringNotContainsString('Something worth keeping happened.', $application);
    }

    /**
     * The retention is sized for the question it answers.
     *
     * "Why did nobody get paged" is asked during an incident review, which is
     * days to weeks after the window closed, and a credential-use question can
     * arrive a quarter later. The application log keeps 14 days and rotates them
     * away; inheriting that would delete the trail before it is read, which is
     * the same silence this whole change removes, arriving late instead of never.
     */
    public function test_the_evidence_channel_keeps_at_least_a_quarter_of_history(): void
    {
        $this->assertGreaterThanOrEqual(90, (int) config('logging.channels.'.EvidenceLog::CHANNEL.'.days'));
    }

    /**
     * The current value of an environment key, absence included.
     *
     * @return array{env: string|null, server: string|null}
     */
    private function currentEnvValue(string $key): array
    {
        return [
            'env' => $_ENV[$key] ?? null,
            'server' => $_SERVER[$key] ?? null,
        ];
    }

    /**
     * Put an environment value back exactly as it was, absence included.
     *
     * @param  array{env: string|null, server: string|null}  $original
     */
    private function restoreEnvValue(string $key, array $original): void
    {
        if ($original['env'] === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $original['env'];
        }

        if ($original['server'] === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $original['server'];
        }
    }
}
