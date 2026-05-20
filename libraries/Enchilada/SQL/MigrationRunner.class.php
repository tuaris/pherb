<?php

/* Enchilada Extras
 * SQL Migration Runner — Flyway-style schema migrations for PDO databases
 * 
 * $Id$
 * 
 * Software License Agreement (BSD License)
 * 
 * Copyright (c) 2013-2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 * 
 * Redistribution and use of this software in source and binary forms, with or without modification, are
 * permitted provided that the following conditions are met:
 * 
 *   Redistributions of source code must retain the above
 *   copyright notice, this list of conditions and the
 *   following disclaimer.
 * 
 *   Redistributions in binary form must reproduce the above
 *   copyright notice, this list of conditions and the
 *   following disclaimer in the documentation and/or other
 *   materials provided with the distribution.
 * 
 *   Neither the name of The Daniel Morante Company, Inc. nor the names of its
 *   contributors may be used to endorse or promote products
 *   derived from this software without specific prior
 *   written permission of The Daniel Morante Company, Inc.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED
 * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A
 * PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR
 * TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF
 * ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

namespace Enchilada\SQL;

/*
 * Flyway-style SQL migration runner for PDO databases (MariaDB/MySQL).
 *
 * Reads numbered SQL files from a migrations directory and applies them
 * in order, tracking applied versions in a schema_migrations table.
 * Each migration runs exactly once.
 *
 * Migration files must follow the naming convention: NNN_description.sql
 * (e.g., 001_initial_schema.sql, 002_add_payments.sql).
 *
 * Usage:
 *   $runner = new MigrationRunner($pdo, '/path/to/schema');
 *   $runner->applyPending();         // Apply all pending migrations
 *   $runner->getStatus();            // Show applied + pending
 *   $runner->applyPending(true);     // Dry-run
 *
 * @author Daniel Morante
 */

class MigrationRunner {

	/** @var \PDO */
	private \PDO $db;

	/** @var string Path to the migrations directory */
	private string $migrationsDir;

	/** @var string Name of the tracking table */
	private string $trackingTable;

	/**
	 * @param \PDO   $db             Connected PDO instance
	 * @param string $migrationsDir  Path to directory containing NNN_*.sql files
	 * @param string $trackingTable  Name of the migration tracking table
	 */
	public function __construct(\PDO $db, string $migrationsDir, string $trackingTable = 'schema_migrations') {
		$this->db = $db;
		$this->migrationsDir = rtrim($migrationsDir, DIRECTORY_SEPARATOR);
		$this->trackingTable = $trackingTable;
	}

	/**
	 * Ensure the migration tracking table exists.
	 */
	private function ensureTrackingTable(): void {
		$table = $this->trackingTable;
		$this->db->exec("
			CREATE TABLE IF NOT EXISTS `{$table}` (
				version    INT UNSIGNED NOT NULL PRIMARY KEY,
				filename   VARCHAR(255) NOT NULL,
				applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
		");
	}

	/**
	 * Get all applied migration version numbers.
	 *
	 * @return array<int>
	 */
	private function getAppliedVersions(): array {
		$table = $this->trackingTable;
		$stmt = $this->db->query("SELECT version FROM `{$table}` ORDER BY version ASC");
		return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
	}

	/**
	 * Discover migration files from the migrations directory.
	 *
	 * Returns an array of [version => filename] sorted by version.
	 * Files must match the pattern NNN_description.sql.
	 *
	 * @return array<int,string>
	 */
	public function discoverMigrations(): array {
		$migrations = [];

		if (!is_dir($this->migrationsDir)) {
			return $migrations;
		}

		$files = scandir($this->migrationsDir);
		foreach ($files as $file) {
			if (preg_match('/^(\d+)[_-].+\.sql$/', $file, $matches)) {
				$version = (int) $matches[1];
				$migrations[$version] = $file;
			}
		}

		ksort($migrations);
		return $migrations;
	}

	/**
	 * Parse a SQL file into individual statements.
	 *
	 * Strips comment lines (-- ...) and blank lines, then splits on semicolons.
	 *
	 * @param  string        $filepath Full path to the SQL file
	 * @return array<string>           SQL statements to execute
	 */
	private function parseSqlFile(string $filepath): array {
		$content = file_get_contents($filepath);
		$lines = explode("\n", $content);

		$sqlLines = [];
		foreach ($lines as $line) {
			$trimmed = trim($line);
			if ($trimmed === '' || str_starts_with($trimmed, '--')) {
				continue;
			}
			$sqlLines[] = $line;
		}

		$sql = implode("\n", $sqlLines);
		$statements = array_filter(
			array_map('trim', explode(';', $sql)),
			fn($s) => $s !== ''
		);

		return array_values($statements);
	}

	/**
	 * Apply all pending migrations.
	 *
	 * @param  bool  $dryRun If true, report what would be applied without executing
	 * @return array{applied:array<string>,skipped:int}
	 */
	public function applyPending(bool $dryRun = false): array {
		$this->ensureTrackingTable();

		$migrations = $this->discoverMigrations();
		$applied = $this->getAppliedVersions();
		$results = [];
		$skipped = 0;

		foreach ($migrations as $version => $filename) {
			if (in_array($version, $applied, true)) {
				$skipped++;
				continue;
			}

			if ($dryRun) {
				$results[] = "[dry-run] Would apply: {$filename}";
				continue;
			}

			$filepath = $this->migrationsDir . DIRECTORY_SEPARATOR . $filename;
			$statements = $this->parseSqlFile($filepath);

			foreach ($statements as $sql) {
				$this->db->exec($sql);
			}

			$table = $this->trackingTable;
			$stmt = $this->db->prepare(
				"INSERT INTO `{$table}` (version, filename) VALUES (:version, :filename)"
			);
			$stmt->execute(['version' => $version, 'filename' => $filename]);

			$results[] = $filename;
		}

		return [
			'applied' => $results,
			'skipped' => $skipped,
		];
	}

	/**
	 * Get migration status.
	 *
	 * @return array{applied:array,pending:array}
	 */
	public function getStatus(): array {
		$this->ensureTrackingTable();

		$table = $this->trackingTable;
		$migrations = $this->discoverMigrations();
		$stmt = $this->db->query(
			"SELECT version, filename, applied_at FROM `{$table}` ORDER BY version ASC"
		);
		$appliedRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
		$appliedVersions = array_column($appliedRows, 'version');

		$pending = [];
		foreach ($migrations as $version => $filename) {
			if (!in_array($version, $appliedVersions, true)) {
				$pending[] = ['version' => $version, 'filename' => $filename];
			}
		}

		return [
			'applied' => $appliedRows,
			'pending' => $pending,
		];
	}

	/**
	 * Reset migration tracking.
	 *
	 * Does NOT drop or modify schema created by migrations.
	 * Only clears the tracking table so all migrations re-apply on next run.
	 */
	public function reset(): void {
		$this->ensureTrackingTable();
		$table = $this->trackingTable;
		$this->db->exec("DELETE FROM `{$table}`");
	}

}
