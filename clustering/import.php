<?php

// Import container.tsv into a SQLite database, generating normalised forms and
// blocking keys for each row.
//
//   php import.php [path-to-tsv] [path-to-db]
//
// Defaults: ../container.tsv  ->  containers.db (in this directory)
//
// The TSV has no header: column 1 = raw container-title, column 2 = ISSN (maybe blank).

require_once(__DIR__ . '/clean.php');

$tsv = isset($argv[1]) ? $argv[1] : __DIR__ . '/../container.tsv';
$dbPath = isset($argv[2]) ? $argv[2] : __DIR__ . '/containers.db';

if (!file_exists($tsv)) {
	fwrite(STDERR, "TSV not found: $tsv\n");
	exit(1);
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Speed up bulk insert.
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('PRAGMA synchronous = OFF');

$db->exec('DROP TABLE IF EXISTS containers');
$db->exec('
	CREATE TABLE containers (
		id          INTEGER PRIMARY KEY,
		raw         TEXT NOT NULL,
		issn        TEXT,
		normalized  TEXT,
		tokens      TEXT,
		series      TEXT,   -- series/new-series marker lifted out of the name
		key_a       TEXT,   -- ordered prefixes (full)
		key_b       TEXT,   -- sorted prefixes (full)
		key_c       TEXT,   -- leading prefix anchor
		key_d       TEXT,   -- initials
		cluster_id  INTEGER
	)
');

$insert = $db->prepare('
	INSERT INTO containers (raw, issn, normalized, tokens, series, key_a, key_b, key_c, key_d)
	VALUES (:raw, :issn, :normalized, :tokens, :series, :key_a, :key_b, :key_c, :key_d)
');

$fh = fopen($tsv, 'r');
if (!$fh) {
	fwrite(STDERR, "Cannot open: $tsv\n");
	exit(1);
}

$db->beginTransaction();

$n = 0;
$empty_keys = 0;
while (($line = fgets($fh)) !== false) {
	$line = rtrim($line, "\r\n");
	if ($line === '') {
		continue;
	}

	$cols = explode("\t", $line);
	$raw = $cols[0];
	$issn = isset($cols[1]) && trim($cols[1]) !== '' ? trim($cols[1]) : null;

	$normalized = normalise_text($raw);
	$keys = make_keys($raw);

	if ($keys['key_a'] === '') {
		$empty_keys++;
	}

	$insert->execute(array(
		':raw'        => $raw,
		':issn'       => $issn,
		':normalized' => $normalized,
		':tokens'     => $keys['tokens'],
		':series'     => $keys['series'],
		':key_a'      => $keys['key_a'],
		':key_b'      => $keys['key_b'],
		':key_c'      => $keys['key_c'],
		':key_d'      => $keys['key_d'],
	));

	$n++;
	if ($n % 20000 === 0) {
		$db->commit();
		$db->beginTransaction();
		fwrite(STDERR, "  $n rows...\n");
	}
}
$db->commit();
fclose($fh);

// Indexes for blocking and lookup.
$db->exec('CREATE INDEX idx_key_a ON containers(key_a)');
$db->exec('CREATE INDEX idx_key_b ON containers(key_b)');
$db->exec('CREATE INDEX idx_key_c ON containers(key_c)');
$db->exec('CREATE INDEX idx_key_d ON containers(key_d)');
$db->exec('CREATE INDEX idx_normalized ON containers(normalized)');
$db->exec('CREATE INDEX idx_issn ON containers(issn)');

fwrite(STDERR, "Imported $n rows into $dbPath ($empty_keys with empty key_a).\n");
