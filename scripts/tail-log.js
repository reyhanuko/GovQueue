/**
 * Tail the application log, on any platform.
 *
 * `php artisan pail` is the usual answer, and it cannot be used here: it forks,
 * and pcntl does not exist on Windows. It was the one process in the dev
 * script that died on a Windows machine - taking the exit code with it, and
 * printing a stack trace at every start.
 *
 * This does the same job in Node, which is already a dependency for Vite, and
 * it works the same on every platform the project is developed on.
 *
 * It prints new lines as they are written. It starts at the end of the file,
 * because the useful lines are the ones written from now on, and a terminal
 * that scrolls through yesterday's exceptions is a terminal nobody reads.
 */
import { existsSync, mkdirSync, openSync, closeSync, readSync, statSync, watch, writeFileSync } from 'node:fs';
import { dirname, resolve, basename } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

const file = resolve(here, '..', 'storage', 'logs', 'laravel.log');
const name = basename(file);

let position = 0;

function size() {
    try {
        return statSync(file).size;
    } catch {
        return null;
    }
}

function read() {
    const current = size();

    // The file has not been created yet - the first request makes it.
    if (current === null) {
        return;
    }

    // Truncated or rotated under us: start again from the top.
    if (current < position) {
        position = 0;
    }

    if (current === position) {
        return;
    }

    const handle = openSync(file, 'r');

    try {
        const buffer = Buffer.alloc(current - position);

        readSync(handle, buffer, 0, buffer.length, position);

        process.stdout.write(buffer.toString('utf8'));
    } finally {
        closeSync(handle);
    }

    position = current;
}

try {
    mkdirSync(dirname(file), { recursive: true });
} catch {
    // Nothing to do; the log directory is Laravel's to make.
}

// The file may not exist yet - the first request makes it. It is never
// truncated: the log is a record, and starting a tail is not a reason to
// empty it.
if (!existsSync(file)) {
    writeFileSync(file, '');
}

// Start at the end, so the terminal shows what is written from now on.
position = size() ?? 0;

process.stdout.write(`Tailing ${name}. Press Ctrl+C to stop.\n\n`);

// The directory is watched rather than the file, because a log that is
// rotated - which the pruning command does - is replaced, and a watch on the
// old inode would then watch nothing.
try {
    watch(dirname(file), (event, changed) => {
        if (changed === name || changed === null) {
            read();
        }
    });
} catch {
    // No watch available: fall back to the poll below.
}

setInterval(read, 1000);
