<?php

declare(strict_types=1);

// Run from cron to keep public/drupalcon_rotterdam.json in sync with the official
// DrupalCon Rotterdam schedule feed. Only handles the three kinds of drift the feed
// can express on its own (session time changed, session removed, session added) —
// location, tags and speakers for new sessions are intentionally left for manual
// follow-up, since the feed carries none of that.

const LOCAL_FILE       = __DIR__ . '/../public/drupalcon_rotterdam.json';
const FEED_URL         = 'https://events.drupal.org/export/rotterdam2026/drupalcon-schedule.json';
const EVENT_YEAR       = 2026;
const EVENT_TIMEZONE   = 'Europe/Amsterdam';
const CANCELLED_PREFIX = '***Cancelled*** ';
const UNKNOWN_LOCATION = 'Unknown';
const HTTP_TIMEOUT_S   = 30;
// The feed occasionally truncates a title (e.g. a field-length bug drops the last
// character or two). If a local title isn't found verbatim in the feed, it's still
// treated as a match to a feed title that is an exact prefix of it, as long as the
// extra length on the local side is no more than this many characters — enough to
// absorb a truncation typo without turning into a loose substring search.
const TITLE_MATCH_MAX_EXTRA_CHARS = 20;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function fetch_feed(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => HTTP_TIMEOUT_S,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'ConClar DrupalCon schedule sync/1.0',
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        fail("Failed to fetch feed: $error");
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) {
        fail("Feed request returned HTTP $status");
    }
    if (trim((string) $body) === '') {
        fail('Feed response was empty');
    }

    // Decoded in object mode (not assoc) so JSON objects and JSON arrays stay
    // distinguishable — see the matching note on load_local_data().
    try {
        $data = json_decode((string) $body, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fail('Feed response was not valid JSON: ' . $e->getMessage());
    }
    if (!is_array($data)) {
        fail('Feed response was not a JSON array');
    }
    foreach ($data as $item) {
        if (!is_object($item) || !isset($item->title, $item->field_when, $item->body)) {
            fail('Feed item is missing an expected field (title/field_when/body)');
        }
    }

    return $data;
}

function load_local_data(string $path): stdClass
{
    if (!is_file($path)) {
        fail("Local file not found: $path");
    }
    $body = file_get_contents($path);
    if ($body === false) {
        fail("Failed to read local file: $path");
    }
    // Decoded in object mode (not assoc): PHP's json_decode($json, true) collapses
    // both `{}` and `[]` into an empty PHP array, so re-encoding loses the original
    // shape (an empty object like "links": {} silently becomes "links": []). Object
    // mode keeps JSON objects as stdClass and JSON arrays as PHP arrays, so encoding
    // back reproduces the original shape exactly.
    try {
        $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fail('Local file was not valid JSON: ' . $e->getMessage());
    }
    if (!is_object($data) || !isset($data->schedule) || !is_array($data->schedule)) {
        fail('Local file does not contain the expected "schedule" array');
    }

    return $data;
}

function decode_text(string $raw): string
{
    return trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/**
 * Parses a feed field_when string, e.g. "Tuesday, 29 Sep 13:30 - Tuesday, 29 Sep 14:15",
 * into a start DateTimeImmutable and a duration in whole minutes. The feed gives no year,
 * so EVENT_YEAR is assumed for both halves.
 *
 * @return array{0: DateTimeImmutable, 1: int}
 */
function parse_feed_when(string $when): array
{
    $pattern = '/^\s*\w+,\s*(\d{1,2})\s+(\w+)\s+(\d{1,2}:\d{2})\s*-\s*\w+,\s*(\d{1,2})\s+(\w+)\s+(\d{1,2}:\d{2})\s*$/';
    if (!preg_match($pattern, $when, $m)) {
        fail("Could not parse feed time: \"$when\"");
    }

    $tz = new DateTimeZone(EVENT_TIMEZONE);
    $start = DateTimeImmutable::createFromFormat(
        'j M Y H:i',
        sprintf('%d %s %d %s', (int) $m[1], $m[2], EVENT_YEAR, $m[3]),
        $tz
    );
    $end = DateTimeImmutable::createFromFormat(
        'j M Y H:i',
        sprintf('%d %s %d %s', (int) $m[4], $m[5], EVENT_YEAR, $m[6]),
        $tz
    );
    if ($start === false || $end === false) {
        fail("Could not parse feed time: \"$when\"");
    }

    $mins = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);

    return [$start, $mins];
}

function slugify(string $title): string
{
    $slug = strtolower($title);
    $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug) ?: $slug;
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

    return trim($slug, '-');
}

function strip_cancelled_prefix(string $title): string
{
    return str_starts_with($title, CANCELLED_PREFIX)
        ? substr($title, strlen(CANCELLED_PREFIX))
        : $title;
}

/**
 * Finds the feed title matching a local title: an exact match if one exists, otherwise
 * the closest feed title that is an exact prefix of the local title (i.e. the local
 * title is the feed title plus some extra trailing characters, within
 * TITLE_MATCH_MAX_EXTRA_CHARS) — see the constant's doc comment. $excludeTitles lists
 * feed titles already claimed by another local session, so one feed item can't match two.
 *
 * @param array<string, mixed> $feedByTitle
 * @param array<string, true> $excludeTitles
 */
function find_feed_match(string $localTitle, array $feedByTitle, array $excludeTitles): ?string
{
    if (isset($feedByTitle[$localTitle]) && !isset($excludeTitles[$localTitle])) {
        return $localTitle;
    }

    $bestMatch = null;
    $bestExtra = null;
    foreach ($feedByTitle as $feedTitle => $entry) {
        if ($feedTitle === '' || isset($excludeTitles[$feedTitle])) {
            continue;
        }
        if (!str_starts_with($localTitle, $feedTitle)) {
            continue;
        }
        $extra = strlen($localTitle) - strlen($feedTitle);
        if ($extra > TITLE_MATCH_MAX_EXTRA_CHARS) {
            continue;
        }
        if ($bestExtra === null || $extra < $bestExtra) {
            $bestExtra = $extra;
            $bestMatch = $feedTitle;
        }
    }

    return $bestMatch;
}

/**
 * PHP's JSON_PRETTY_PRINT hardcodes 4-space indentation; the data file uses 2-space
 * (matching the project's JS tooling). Halving each line's leading whitespace keeps
 * future diffs limited to the actual data changes instead of reformatting the whole file.
 */
function reindent_json(string $json): string
{
    $lines = explode("\n", $json);
    foreach ($lines as &$line) {
        if (preg_match('/^( +)/', $line, $m)) {
            $line = str_repeat(' ', (int) (strlen($m[1]) / 2)) . substr($line, strlen($m[1]));
        }
    }

    return implode("\n", $lines);
}

// ---- main ----

$feedItems = fetch_feed(FEED_URL);
$localData = load_local_data(LOCAL_FILE);

$feedByTitle = [];
foreach ($feedItems as $item) {
    $title = decode_text((string) $item->title);
    [$start, $mins] = parse_feed_when((string) $item->field_when);
    $feedByTitle[$title] = [
        'title' => $title,
        'start' => $start,
        'mins' => $mins,
        'desc' => decode_text((string) $item->body),
    ];
}

// Every local title, with any cancellation marker stripped, so a feed item can't be
// mistaken for "new" just because the matching local session was already cancelled.
$localTitlesStripped = [];
foreach ($localData->schedule as $session) {
    $localTitlesStripped[strip_cancelled_prefix((string) $session->title)] = true;
}

$updates = [];
$removals = [];
$matchedFeedTitles = [];
foreach ($localData->schedule as $index => $session) {
    $title = (string) $session->title;
    if (str_starts_with($title, CANCELLED_PREFIX)) {
        continue; // Already cancelled: leave untouched on every subsequent run.
    }
    $feedTitleKey = find_feed_match($title, $feedByTitle, $matchedFeedTitles);
    if ($feedTitleKey === null) {
        $removals[] = $index;
        continue;
    }
    $matchedFeedTitles[$feedTitleKey] = true;

    $feedEntry = $feedByTitle[$feedTitleKey];
    $localStart = new DateTimeImmutable((string) $session->datetime);
    $localMins = (int) $session->mins;
    if ($localStart->getTimestamp() !== $feedEntry['start']->getTimestamp() || $localMins !== $feedEntry['mins']) {
        $updates[$index] = [
            'datetime' => $feedEntry['start']->format(DateTimeInterface::ATOM),
            'mins' => (string) $feedEntry['mins'],
        ];
    }
}

$additions = [];
foreach ($feedByTitle as $title => $entry) {
    if (isset($matchedFeedTitles[$title])) {
        continue; // Already matched (exactly or via the truncation-tolerant fallback) above.
    }
    if (!isset($localTitlesStripped[$title])) {
        $additions[] = $entry;
    }
}

if (!$updates && !$removals && !$additions) {
    echo 'No changes detected.' . PHP_EOL;
    exit(0);
}

$backupPath = preg_replace('/\.json$/', '', LOCAL_FILE) . '.' . date('Ymd-His') . '.json';
if (!rename(LOCAL_FILE, $backupPath)) {
    fail('Failed to back up ' . LOCAL_FILE . " to $backupPath");
}

foreach ($updates as $index => $changes) {
    $localData->schedule[$index]->datetime = $changes['datetime'];
    $localData->schedule[$index]->mins = $changes['mins'];
}

foreach ($removals as $index) {
    $session = $localData->schedule[$index];
    if (!str_starts_with((string) $session->title, CANCELLED_PREFIX)) {
        $session->title = CANCELLED_PREFIX . $session->title;
    }
    if (!str_starts_with((string) $session->desc, '<strike>')) {
        $session->desc = '<strike>' . $session->desc . '</strike>';
    }
}

foreach ($additions as $entry) {
    $newSession = new stdClass();
    $newSession->id = slugify($entry['title']);
    $newSession->title = $entry['title'];
    $newSession->tags = [];
    $newSession->datetime = $entry['start']->format(DateTimeInterface::ATOM);
    $newSession->mins = (string) $entry['mins'];
    $newSession->loc = [UNKNOWN_LOCATION];
    $newSession->people = [];
    $newSession->desc = $entry['desc'];
    $newSession->links = new stdClass();
    $localData->schedule[] = $newSession;
}

$json = json_encode($localData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    fail('Failed to encode updated data as JSON: ' . json_last_error_msg());
}
$json = reindent_json($json);

if (file_put_contents(LOCAL_FILE, $json, LOCK_EX) === false) {
    fail('Failed to write updated data to ' . LOCAL_FILE);
}

printf(
    "Updated %s: %d time change(s), %d cancellation(s), %d new session(s). Backup: %s%s",
    LOCAL_FILE,
    count($updates),
    count($removals),
    count($additions),
    basename($backupPath),
    PHP_EOL
);
