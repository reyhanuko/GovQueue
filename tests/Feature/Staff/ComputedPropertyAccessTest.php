<?php

namespace Tests\Feature\Staff;

use App\Livewire\Display\PublicQueueDisplay;
use App\Livewire\Staff\QueueConsole;
use Livewire\Attributes\Computed;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Guards the one Livewire rule this codebase has been bitten by.
 *
 * A #[Computed] property must be read as a property: $this->window, not
 * $this->window(). The method form re-runs the query every time, which is how
 * QueueConsole came to resolve the same window fifteen times per render.
 *
 * This exists because the mistake is invisible. Livewire ships
 * CannotCallComputedDirectlyException, which reads like a guard rail, but
 * nothing routes a plain method call to it - #[Computed] hooks magic property
 * access, and the underlying public method stays an ordinary callable method.
 *
 * A comment in QueueConsole claimed for years that calling a computed as a
 * method "throws by design, so a regression here is a loud failure rather than
 * a silent slowdown". It does not throw. It silently re-queries. The comment was
 * worse than no comment, because it stopped anyone from checking.
 *
 * So this checks the source instead of trusting a promise written next to it.
 * QueueConsoleQueryCountTest remains the backstop for anything this misses.
 */
class ComputedPropertyAccessTest extends TestCase
{
    /**
     * Livewire components in this project that declare #[Computed] properties.
     *
     * Listed rather than discovered, because a component added later has to be
     * added here too - which is the point. Discovering them silently is what
     * makes a guard rot into a comment.
     *
     * @return array<string, array{class-string}>
     */
    public static function components(): array
    {
        return [
            'staff queue console' => [QueueConsole::class],
            'public queue display' => [PublicQueueDisplay::class],
        ];
    }

    /**
     * @param  class-string  $component
     */
    #[DataProvider('components')]
    public function test_no_computed_property_is_called_as_a_method(string $component): void
    {
        $reflection = new ReflectionClass($component);

        $computed = [];

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes() as $attribute) {
                if ($attribute->getName() === Computed::class) {
                    $computed[] = $method->getName();
                }
            }
        }

        $this->assertNotEmpty(
            $computed,
            $component.' declares no #[Computed] properties. Either the attribute moved '
                .'or this test is looking in the wrong place, and both mean it has stopped working.',
        );

        $source = file_get_contents((string) $reflection->getFileName());
        $lines = explode("\n", $source);

        $offenders = [];

        foreach ($computed as $name) {
            // \$this->name() - the method form, which bypasses the memo.
            $pattern = '/\$this->'.preg_quote($name, '/').'\(\)/';

            foreach ($lines as $index => $line) {
                // Docblocks quote the wrong form deliberately, to warn about it.
                // Flagging those would train people to ignore the failure.
                if (trim($line) === '' || str_starts_with(trim($line), '*')) {
                    continue;
                }

                if (preg_match($pattern, $line)) {
                    $offenders[] = sprintf('%s:%d', basename((string) $reflection->getFileName()), $index + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A #[Computed] property is being called as a method, which bypasses the memo and '
            .'re-runs the query. Read it as a property instead: $this->window, not $this->window(). '
            .'This fails silently otherwise - it does not throw.'
        );
    }
}
