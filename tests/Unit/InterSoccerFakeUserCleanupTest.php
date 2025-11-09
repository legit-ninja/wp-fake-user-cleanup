<?php

use PHPUnit\Framework\TestCase;

class InterSoccerFakeUserCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['mock_dbdelta'] = [];
        $GLOBALS['mock_options'] = [];
    }

    public function testEnsureTempTableCreatesTableWithBigint()
    {
        global $wpdb, $mock_dbdelta;
        $wpdb = new FakeWpdb();
        $wpdb->existingTables = [];

        $instance = new InterSoccer_Fake_User_Cleanup();
        $this->invokePrivateMethod($instance, 'ensure_temp_table');

        $this->assertNotEmpty($mock_dbdelta, 'dbDelta should be invoked for new temp table creation.');
        $this->assertStringContainsString('bigint(20) UNSIGNED', $mock_dbdelta[0]);
        $this->assertStringContainsString('PRIMARY KEY (id)', $mock_dbdelta[0]);
        $this->assertStringContainsString('KEY email_idx (email)', $mock_dbdelta[0]);
        $this->assertStringContainsString('score tinyint(3)', $mock_dbdelta[0]);
        $this->assertStringContainsString('reason text', $mock_dbdelta[0]);
        $this->assertStringContainsString('needs_review tinyint(1)', $mock_dbdelta[0]);
    }

    public function testEnsureTempTableUpgradesExistingSchemaAndAddsMissingIndexes()
    {
        global $wpdb, $mock_dbdelta;
        $wpdb = new FakeWpdb();
        $wpdb->existingTables = ['wp_intersoccer_temp_fake_users'];
        $wpdb->fieldTypes['wp_intersoccer_temp_fake_users']['id'] = 'mediumint(9)';
        $wpdb->indexes['wp_intersoccer_temp_fake_users'] = [
            ['Key_name' => 'PRIMARY']
        ];

        $instance = new InterSoccer_Fake_User_Cleanup();
        $this->invokePrivateMethod($instance, 'ensure_temp_table');

        $this->assertEmpty($mock_dbdelta, 'dbDelta should not run when table exists.');
        $this->assertTrue(
            $this->containsQuery($wpdb->queries, 'ALTER TABLE wp_intersoccer_temp_fake_users MODIFY COLUMN id bigint(20) UNSIGNED NOT NULL'),
            'Expected temp table ID column upgrade query.'
        );
        $this->assertTrue(
            $this->containsQuery($wpdb->queries, 'ALTER TABLE wp_intersoccer_temp_fake_users ADD INDEX email_idx (email)'),
            'Expected email index creation query.'
        );
        $this->assertTrue(
            $this->containsQuery($wpdb->queries, 'ALTER TABLE wp_intersoccer_temp_fake_users ADD INDEX registered_idx (registered)'),
            'Expected registered index creation query.'
        );
        $this->assertTrue(
            $this->containsQuery($wpdb->queries, 'ALTER TABLE wp_intersoccer_temp_fake_users ADD COLUMN score'),
            'Expected score column addition.'
        );
        $this->assertTrue(
            $this->containsQuery($wpdb->queries, 'ALTER TABLE wp_intersoccer_temp_fake_users ADD COLUMN reason'),
            'Expected reason column addition.'
        );
        $this->assertTrue(
            $this->containsQuery($wpdb->queries, 'ALTER TABLE wp_intersoccer_temp_fake_users ADD COLUMN needs_review'),
            'Expected needs_review column addition.'
        );
        $this->assertTrue(
            $this->containsQuery($wpdb->queries, 'ALTER TABLE wp_intersoccer_temp_fake_users ADD COLUMN review_notes'),
            'Expected review_notes column addition.'
        );
    }

    public function testActivateEnsuresSchemaForTempAndAuditTables()
    {
        global $wpdb, $mock_dbdelta;
        $wpdb = new FakeWpdb();
        $wpdb->existingTables = ['wp_intersoccer_temp_fake_users', 'wp_intersoccer_cleanup_audit'];
        $wpdb->fieldTypes['wp_intersoccer_temp_fake_users']['id'] = 'mediumint(9)';
        $wpdb->fieldTypes['wp_intersoccer_cleanup_audit']['user_id'] = 'mediumint(9)';
        $wpdb->indexes['wp_intersoccer_temp_fake_users'] = [
            ['Key_name' => 'PRIMARY'],
            ['Key_name' => 'email_idx']
        ];

        InterSoccer_Fake_User_Cleanup::activate();

        $this->assertTrue(
            $this->containsQuery($wpdb->queries, 'ALTER TABLE wp_intersoccer_temp_fake_users MODIFY COLUMN id bigint(20) UNSIGNED NOT NULL'),
            'Activation should upgrade temp table ID column.'
        );
        $this->assertTrue(
            $this->containsQuery($wpdb->queries, 'ALTER TABLE wp_intersoccer_temp_fake_users ADD INDEX registered_idx (registered)'),
            'Activation should ensure registered index exists.'
        );
        $this->assertTrue(
            $this->containsQuery($wpdb->queries, 'ALTER TABLE wp_intersoccer_cleanup_audit MODIFY COLUMN user_id bigint(20) UNSIGNED NOT NULL'),
            'Activation should upgrade audit table user_id column.'
        );
    }

    public function testSaveScanProgressStoresOptionPerSession()
    {
        global $mock_options;
        $instance = new InterSoccer_Fake_User_Cleanup();
        $sessionId = 'session-123';
        $progress = ['session_id' => $sessionId, 'processed' => 5];

        $this->invokePrivateMethod($instance, 'save_scan_progress', [$sessionId, $progress]);

        $this->assertArrayHasKey('intersoccer_scan_progress_' . $sessionId, $mock_options);
        $this->assertSame($progress, $mock_options['intersoccer_scan_progress_' . $sessionId]);
    }

    public function testSaveCleanupProgressStoresOptionPerSession()
    {
        global $mock_options;
        $instance = new InterSoccer_Fake_User_Cleanup();
        $sessionId = 'cleanup-456';
        $progress = ['session_id' => $sessionId, 'processed' => 2];

        $this->invokePrivateMethod($instance, 'save_cleanup_progress', [$sessionId, $progress]);

        $this->assertArrayHasKey('intersoccer_cleanup_progress_' . $sessionId, $mock_options);
        $this->assertSame($progress, $mock_options['intersoccer_cleanup_progress_' . $sessionId]);
    }

    private function containsQuery(array $queries, string $expected): bool
    {
        foreach ($queries as $query) {
            if (stripos($query, $expected) !== false) {
                return true;
            }
        }
        return false;
    }

    private function invokePrivateMethod($object, string $methodName, array $args = [])
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $args);
    }
}

class FakeWpdb
{
    public $prefix = 'wp_';
    public $existingTables = [];
    public $fieldTypes = [];
    public $indexes = [];
    public $queries = [];

    public function get_charset_collate()
    {
        return 'utf8mb4_unicode_ci';
    }

    public function get_var($sql)
    {
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/i", $sql, $matches)) {
            return in_array($matches[1], $this->existingTables, true) ? $matches[1] : null;
        }

        return null;
    }

    public function get_row($sql)
    {
        if (preg_match("/SHOW FIELDS FROM ([^ ]+) LIKE '([^']+)'/i", $sql, $matches)) {
            $table = $matches[1];
            $field = $matches[2];
            if (isset($this->fieldTypes[$table][$field])) {
                return (object) ['Type' => $this->fieldTypes[$table][$field]];
            }
            return null;
        }

        return null;
    }

    public function get_results($sql)
    {
        if (preg_match("/SHOW INDEX FROM ([^ ]+)/i", $sql, $matches)) {
            $table = $matches[1];
            return $this->indexes[$table] ?? [];
        }

        return [];
    }

    public function query($sql)
    {
        $this->queries[] = $sql;
        return true;
    }
}

