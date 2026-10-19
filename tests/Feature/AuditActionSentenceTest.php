<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\AuditLog;
use Tests\TestCase;

/**
 * Every action the application writes has to read as English.
 *
 * The audit trail is shown to an administrator, and the dashboard's "Recent
 * changes" uses the same sentences. An action with no sentence falls back to a
 * humanised key - "Auth login", "User activated", "Appointment confirmed" -
 * which is code wearing a sentence's clothes. It was in the dashboard that it
 * was most visible: somebody signing in and the dashboard announcing it in
 * snake_case.
 *
 * So this is a guard, not a spot check. It reads the source for the actions the
 * application writes, works out the ones it derives from an appointment's
 * status, and holds the sentence list to exactly that set.
 *
 * Both directions, because both rot:
 *
 *  - An action with no sentence renders as a key. That is the bug this was
 *    written for.
 *  - A sentence for an action that cannot happen is a promise the trail does
 *    not keep. "holiday.updated" sat in the list for the length of a project
 *    in which no holiday could be edited at all - there is no route to edit
 *    one, only to declare and remove it.
 */
class AuditActionSentenceTest extends TestCase
{
    public function test_every_action_the_application_writes_has_a_sentence(): void
    {
        $written = $this->actionsWrittenInTheSource();

        foreach ($this->actionsDerivedFromAStatus() as $action) {
            $written[] = $action;
        }

        $written = array_values(array_unique($written));

        $missing = [];

        foreach ($written as $action) {
            if (! $this->hasSentenceFor($action)) {
                $missing[] = $action;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'These actions are written by the application and have no sentence, so they '
                .'render as a key. Add one to AuditLog::actionSentence().'
        );
    }

    public function test_there_is_no_sentence_for_an_action_that_cannot_happen(): void
    {
        $possible = $this->actionsWrittenInTheSource();

        foreach ($this->actionsDerivedFromAStatus() as $action) {
            $possible[] = $action;
        }

        $possible = array_unique($possible);

        $stale = [];

        foreach ($this->sentences() as $action => $sentence) {
            if (! in_array($action, $possible, true)) {
                $stale[] = $action;
            }
        }

        $this->assertSame(
            [],
            $stale,
            'These actions have a sentence but nothing writes them, so the sentence is '
                .'a promise the trail does not keep. Remove the sentence, or write the action.'
        );
    }

    /**
     * The actions the application writes, read out of the source.
     *
     * Read from the source rather than from a list, because a list is a thing
     * to forget to update - which is the whole problem this test is about.
     *
     * The whole call is read, not just its first argument, because an action
     * can be chosen by a ternary: StaffAdminService records
     * 'user.activated' or 'user.deactivated' depending on the new state, and a
     * scan of the first argument alone would have missed both.
     *
     * @return array<int, string>
     */
    private function actionsWrittenInTheSource(): array
    {
        $actions = [];

        foreach ($this->phpFilesIn(app_path()) as $file) {
            $source = file_get_contents($file);

            preg_match_all('/AuditLog::record\((.*?)\);/s', $source, $calls);

            foreach ($calls[1] as $call) {
                // An action key is a dotted lowercase word. The one that is
                // built by concatenation - 'appointment.' plus a status - has
                // nothing after the dot and so is not matched here; it is
                // covered by actionsDerivedFromAStatus() instead.
                preg_match_all("/'([a-z_]+\.[a-z_]+)'/", $call, $matches);

                foreach ($matches[1] as $action) {
                    $actions[] = $action;
                }
            }
        }

        sort($actions);

        return array_values(array_unique($actions));
    }

    /**
     * The actions the queue derives from an appointment's status.
     *
     * QueueService records 'appointment.' plus the status it moved to, so the
     * set of possible actions is every status a ticket can be transitioned
     * to. A ticket is created pending and never transitioned back to it, so
     * pending is not among them.
     *
     * @return array<int, string>
     */
    private function actionsDerivedFromAStatus(): array
    {
        $actions = [];

        foreach (AppointmentStatus::cases() as $from) {
            foreach (AppointmentStatus::cases() as $to) {
                if ($from->canTransitionTo($to)) {
                    $actions[] = 'appointment.'.$to->value;
                }
            }
        }

        sort($actions);

        return array_values(array_unique($actions));
    }

    /**
     * The sentences the model knows.
     *
     * @return array<string, string>
     */
    private function sentences(): array
    {
        return (new \ReflectionClass(AuditLog::class))->getConstant('ACTION_SENTENCES');
    }

    private function hasSentenceFor(string $action): bool
    {
        return array_key_exists($action, $this->sentences());
    }

    /**
     * Every PHP file under a directory, recursively.
     *
     * @return array<int, string>
     */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
