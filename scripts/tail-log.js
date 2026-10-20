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
import { existsSync, mkdirSync, openSync, closeSync, readSync, readdirSync, statSync, watch, writeFileSync } from 'node:fs';
import { dirname, resolve, basename } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

const directory = resolve(here, '..', 'storage', 'logs');

/**
 * The log files this application writes.
 *
 * The channel is daily, so the file is laravel-YYYY-MM-DD.log and the name
 * changes at midnight. The emergency channel - the fallback for when the normal
 * handler is broken - writes to the undated laravel.log. Both are matched, and
 * whichever was written to most recently is the one being tailed, so a rollover
 * at midnight and the emergency path are both covered without special cases.
 */
const LOG_NAME = /^laravel(?:-\d{4}-\d{2}-\d{2})?\.log$/;

let tailing = null;
let position = 0;

function sizeOf(file) {
    try {
        return statSync(file).size;
    } catch {
        return null;
    }
}

/**
 * The log file to tail: the most recently written one.
 */
function currentLog() {
    let newest = null;
    let newestTime = 0;

    let entries = [];

    try {
        entries = readdirSync(directory, { withFileTypes: true });
    } catch {
        return null;
    }

    for (const entry of entries) {
        if (!entry.isFile() || !LOG_NAME.test(entry.name)) {
            continue;
        }

        const { mtimeMs } = statSync(resolve(directory, entry.name));

        if (mtimeMs >= newestTime) {
            newestTime = mtimeMs;
            newest = entry.name;
        }
    }

    return newest;
}

function read() {
    const name = currentLog();

    if (!name) {
        return;
    }

    if (name !== tailing) {
        // A different file: the day rolled over, or the emergency channel
        // took over. Start it from the top - it is new, so it is nearly empty.
        tailing = name;
        position = 0;
    }

    const file = resolve(directory, name);
    const current = sizeOf(file);

    // The file has not been created yet - the first request makes it.
    if (current === null) {
        return;
    }

    // Truncated under us: start again from the top.
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
    mkdirSync(directory, { recursive: true });
} catch {
    // Nothing to do; the log directory is Laravel's to make.
}

if (!existsSync(directory)) {
    process.stdout.write('No log directory yet. It appears when the first request is logged.\n');
}

// The first file is created by the first request, so there may be nothing to
// tail yet. That is not an error: the poll below will find it.
const first = currentLog();

if (first) {
    tailing = first;
    position = sizeOf(resolve(directory, first)) ?? 0;
}

process.stdout.write(
    `Tailing ${first ?? 'the application log'}. Press Ctrl+C to stop.\n\n`,
);

// The directory is watched rather than the file, because a log that is
// rotated is replaced, and a watch on the old inode would then watch nothing.
try {
    watch(directory, () => read());
} catch {
    // No watch available: fall back to the poll below.
}

setInterval(read, 1000);
