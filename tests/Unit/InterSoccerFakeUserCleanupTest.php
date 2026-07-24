<?php

use PHPUnit\Framework\TestCase;

class InterSoccerFakeUserCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['mock_dbdelta'] = [];
        $GLOBALS['mock_options'] = [];
        $GLOBALS['mock_transients'] = [];
        $GLOBALS['mock_deleted_users'] = [];
        unset($GLOBALS['mock_wp_delete_user_return']);
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
        $this->assertTrue(
            $this->containsQuery($wpdb->queries, 'ALTER TABLE wp_intersoccer_temp_fake_users ADD INDEX needs_review_idx'),
            'Expected needs_review index for review export.'
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

    public function testMaybeUpgradeSchemaSkipsWhenVersionCurrent()
    {
        global $wpdb, $mock_options;
        $mock_options['intersoccer_fake_cleanup_schema_version'] = 4;
        $wpdb = new FakeWpdb();
        $wpdb->existingTables = [];

        $instance = new InterSoccer_Fake_User_Cleanup();
        $this->invokePrivateMethod($instance, 'maybe_upgrade_schema');

        $this->assertEmpty($wpdb->queries, 'Schema upgrade should be skipped when version is current.');
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

    public function testEvaluateUserAddsMissingIntersoccerPlayersReasonWhenNoPlayers()
    {
        global $wpdb, $mock_user_meta;
        $wpdb = new FakeWpdb();
        $wpdb->existingTables = ['wp_users', 'wp_usermeta'];
        $wpdb->get_var_returns = [5, 10];
        $mock_user_meta = [
            1 => [
                'first_name' => 'Jane',
                'last_name'  => 'Doe',
                'intersoccer_players' => [],
            ],
        ];
        $user = (object) [
            'ID' => 1,
            'user_email' => 'jane.doe@example.com',
            'user_login' => 'janedoe',
            'user_registered' => '2025-07-15 10:00:00',
        ];
        $instance = new InterSoccer_Fake_User_Cleanup();
        $result = $this->invokePrivateMethod($instance, 'evaluate_user', [$user, false]);
        $this->assertIsArray($result);
        $codes = array_column($result['reasons'], 'code');
        $this->assertContains('missing_intersoccer_players', $codes);
    }

    public function testEvaluateUserUsesBatchContextForCohort()
    {
        global $wpdb;
        $wpdb = new FakeWpdb();
        $user = (object) [
            'ID' => 42,
            'user_email' => 'abcdefgh12@gmail.com',
            'user_login' => 'abcdefgh12',
            'user_registered' => '2025-07-15 10:00:00',
        ];
        $batch_context = array(
            'meta' => array(
                42 => array(
                    'first_name' => '',
                    'last_name' => '',
                    'intersoccer_players' => serialize(array()),
                ),
            ),
            'meta_counts' => array(42 => 2),
            'cohort_map' => array('2025-07-15 10:00:00' => 30),
        );

        $instance = new InterSoccer_Fake_User_Cleanup();
        $result = $this->invokePrivateMethod($instance, 'evaluate_user', [$user, false, $batch_context]);

        $this->assertEmpty($wpdb->queries, 'Batch context should avoid per-user cohort COUNT queries.');
        $codes = array_column($result['reasons'], 'code');
        $this->assertContains('burst_registration_window', $codes);
    }

    public function testBuildScanBatchContextFetchesMetaInBulk()
    {
        global $wpdb;
        $wpdb = new FakeWpdb();
        $wpdb->get_results_returns = array(
            array(
                (object) array('user_id' => 1, 'meta_key' => 'first_name', 'meta_value' => 'Jane'),
                (object) array('user_id' => 1, 'meta_key' => 'last_name', 'meta_value' => 'Doe'),
            ),
            array(
                (object) array('user_id' => 1, 'meta_count' => 5),
            ),
        );

        $users = array(
            (object) array('ID' => 1, 'user_email' => 'jane@example.com', 'user_login' => 'jane', 'user_registered' => '2025-07-15 10:00:00'),
        );

        $instance = new InterSoccer_Fake_User_Cleanup();
        $context = $this->invokePrivateMethod($instance, 'build_scan_batch_context', [$users, array()]);

        $this->assertSame('Jane', $context['meta'][1]['first_name']);
        $this->assertSame(5, $context['meta_counts'][1]);
        $this->assertCount(2, $wpdb->queries);
        $this->assertStringContainsString('meta_key IN', $wpdb->queries[0]);
        $this->assertStringContainsString('GROUP BY user_id', $wpdb->queries[1]);
    }

    public function testInsertFakeUsersBatchBuildsSingleInsert()
    {
        global $wpdb;
        $wpdb = new FakeWpdb();

        $instance = new InterSoccer_Fake_User_Cleanup();
        $this->invokePrivateMethod($instance, 'insert_fake_users_batch', [[
            array(
                'id' => 1,
                'email' => 'fake@example.com',
                'registered' => '2025-07-15 10:00:00',
                'score' => 80,
                'reason' => '[]',
                'needs_review' => 0,
                'review_notes' => null,
            ),
            array(
                'id' => 2,
                'email' => 'fake2@example.com',
                'registered' => '2025-07-15 10:00:01',
                'score' => 90,
                'reason' => '[]',
                'needs_review' => 0,
                'review_notes' => null,
            ),
        ]]);

        $this->assertCount(1, $wpdb->queries);
        $this->assertStringContainsString('INSERT INTO wp_intersoccer_temp_fake_users', $wpdb->queries[0]);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $wpdb->queries[0]);
        $this->assertEmpty($wpdb->replace_calls);
    }

    public function testPrefetchCohortMapStoresTransient()
    {
        global $wpdb, $mock_transients;
        $wpdb = new FakeWpdb();
        $wpdb->get_results_return = array(
            (object) array('user_registered' => '2025-07-15 10:00:00', 'cohort_count' => 30),
        );

        $instance = new InterSoccer_Fake_User_Cleanup();
        $map = $this->invokePrivateMethod($instance, 'prefetch_cohort_map', ['session-abc', '2025-07-01', '2025-07-31']);

        $this->assertSame(array('2025-07-15 10:00:00' => 30), $map);
        $this->assertSame(
            array('2025-07-15 10:00:00' => 30),
            $mock_transients['intersoccer_scan_cohorts_session-abc']
        );
    }

    public function testDeleteCohortTransientRemovesStoredMap()
    {
        global $mock_transients;
        $mock_transients['intersoccer_scan_cohorts_session-abc'] = array('2025-07-15 10:00:00' => 30);

        $instance = new InterSoccer_Fake_User_Cleanup();
        $this->invokePrivateMethod($instance, 'delete_cohort_transient', ['session-abc']);

        $this->assertArrayNotHasKey('intersoccer_scan_cohorts_session-abc', $mock_transients);
    }

    public function testGetScanResultsUsesLastSummaryWhenSessionEmptyAndTempTableHasRows()
    {
        global $wpdb, $mock_options;
        $mock_options['intersoccer_fake_cleanup_schema_version'] = 4;
        $mock_options['intersoccer_scan_last_summary'] = array(
            'total_processed' => 100,
            'fake_found'     => 12,
            'safe_found'     => 88,
            'total_users'    => 100,
        );
        $wpdb = new FakeWpdb();
        $wpdb->prefix = 'wp_';
        $wpdb->get_var_returns = [1];
        $wpdb->get_results_return = [];

        $instance = new InterSoccer_Fake_User_Cleanup();
        $results = $this->invokePrivateMethod($instance, 'get_scan_results', [null]);
        $this->assertSame(100, $results['total_processed']);
        $this->assertSame(12, $results['fake_found']);
        $this->assertSame(88, $results['safe_found']);
        $this->assertSame(100, $results['total_users']);
    }

    public function testBuildActivityMetaFlagSkipsStaleWcLastActive()
    {
        $instance = new InterSoccer_Fake_User_Cleanup();
        $flag = $this->invokePrivateMethod($instance, 'build_activity_meta_flag', [
            'wc_last_active',
            '1754438400',
            6
        ]);
        $this->assertNull($flag);
    }

    public function testBuildActivityMetaFlagFlagsRecentWcLastActive()
    {
        $instance = new InterSoccer_Fake_User_Cleanup();
        $recent = (string) (time() - (86400 * 30));
        $flag = $this->invokePrivateMethod($instance, 'build_activity_meta_flag', [
            'wc_last_active',
            $recent,
            6
        ]);
        $this->assertIsArray($flag);
        $this->assertSame('recent_activity_meta', $flag['code']);
        $this->assertStringContainsString('wc_last_active', $flag['detail']);
    }

    public function testBuildActivityMetaFlagStrictModeForAnyActivityMeta()
    {
        $instance = new InterSoccer_Fake_User_Cleanup();
        $flag = $this->invokePrivateMethod($instance, 'build_activity_meta_flag', [
            'wc_last_active',
            '1754438400',
            0
        ]);
        $this->assertSame('recent_activity_meta', $flag['code']);
        $this->assertSame('wc_last_active', $flag['detail']);
    }

    public function testBuildActivityMetaFlagEmptySessionTokensDoesNotBlock()
    {
        $instance = new InterSoccer_Fake_User_Cleanup();
        $flag = $this->invokePrivateMethod($instance, 'build_activity_meta_flag', [
            'session_tokens',
            'a:0:{}',
            6
        ]);
        $this->assertNull($flag);

        $flag = $this->invokePrivateMethod($instance, 'build_activity_meta_flag', [
            'session_tokens',
            [],
            6
        ]);
        $this->assertNull($flag);
    }

    public function testBuildActivityMetaFlagExpiredSessionTokensDoesNotBlock()
    {
        $instance = new InterSoccer_Fake_User_Cleanup();
        $flag = $this->invokePrivateMethod($instance, 'build_activity_meta_flag', [
            'session_tokens',
            [
                'deadbeef' => [
                    'expiration' => time() - 3600,
                    'login' => time() - 7200,
                ],
            ],
            6
        ]);
        $this->assertNull($flag);
    }

    public function testBuildActivityMetaFlagActiveSessionTokensBlocks()
    {
        $instance = new InterSoccer_Fake_User_Cleanup();
        $flag = $this->invokePrivateMethod($instance, 'build_activity_meta_flag', [
            'session_tokens',
            [
                'alivebeef' => [
                    'expiration' => time() + 3600,
                    'login' => time(),
                ],
            ],
            6
        ]);
        $this->assertIsArray($flag);
        $this->assertSame('recent_activity_meta', $flag['code']);
        $this->assertSame('session_tokens', $flag['detail']);
    }

    public function testBuildActivityMetaFlagEmptyMetaDoesNotBlock()
    {
        $instance = new InterSoccer_Fake_User_Cleanup();
        $flag = $this->invokePrivateMethod($instance, 'build_activity_meta_flag', [
            'last_login',
            '',
            6
        ]);
        $this->assertNull($flag);
    }

    public function testBuildActivityMetaFlagUnparseableTimestampBlocks()
    {
        $instance = new InterSoccer_Fake_User_Cleanup();
        $flag = $this->invokePrivateMethod($instance, 'build_activity_meta_flag', [
            'last_login',
            'not-a-date',
            6
        ]);
        $this->assertSame('recent_activity_meta', $flag['code']);
        $this->assertSame('last_login', $flag['detail']);
    }

    public function testParseActivityMetaTimestampHandlesNumericAndDatetime()
    {
        $instance = new InterSoccer_Fake_User_Cleanup();
        $numeric = $this->invokePrivateMethod($instance, 'parse_activity_meta_timestamp', [
            'wc_last_active',
            '1754438400'
        ]);
        $this->assertSame(1754438400, $numeric);

        $datetime = $this->invokePrivateMethod($instance, 'parse_activity_meta_timestamp', [
            'last_login',
            '2025-08-06 12:00:00'
        ]);
        $this->assertIsInt($datetime);
        $this->assertGreaterThan(0, $datetime);
    }

    public function testGetUserSafetyFlagsBatchHardFlagsWhenActivityChecksDisabled()
    {
        global $wpdb;
        $wpdb = new FakeWpdb();
        $wpdb->prefix = 'wp_';
        $wpdb->get_col_returns = [
            ['42'],
            [],
        ];

        $instance = new InterSoccer_Fake_User_Cleanup();
        $map = $this->invokePrivateMethod($instance, 'get_user_safety_flags_batch', [[42, 99], 6, false]);

        $this->assertCount(1, $map[42]);
        $this->assertSame('customer_has_orders', $map[42][0]['code']);
        $this->assertEmpty($map[99]);
        $this->assertFalse($this->containsQuery($wpdb->queries, 'wp_usermeta'));
    }

    public function testGetUserSafetyFlagsBatchSkipsActivityWhenDisabled()
    {
        global $wpdb;
        $wpdb = new FakeWpdb();
        $wpdb->prefix = 'wp_';
        $wpdb->get_col_returns = [
            [],
            [],
        ];
        $recent = (string) (time() - (86400 * 30));
        $wpdb->get_results_returns = [
            [(object) ['user_id' => 42, 'meta_key' => 'wc_last_active', 'meta_value' => $recent]],
        ];

        $instance = new InterSoccer_Fake_User_Cleanup();
        $map = $this->invokePrivateMethod($instance, 'get_user_safety_flags_batch', [[42], 6, false]);

        $this->assertEmpty($map[42]);
        $this->assertFalse($this->containsQuery($wpdb->queries, 'wp_usermeta'));
    }

    public function testGetUserSafetyFlagsBatchIncludesActivityWhenEnabled()
    {
        global $wpdb;
        $wpdb = new FakeWpdb();
        $wpdb->prefix = 'wp_';
        $wpdb->get_col_returns = [
            [],
            [],
        ];
        $recent = (string) (time() - (86400 * 30));
        $wpdb->get_results_returns = [
            [(object) ['user_id' => 42, 'meta_key' => 'wc_last_active', 'meta_value' => $recent]],
        ];

        $instance = new InterSoccer_Fake_User_Cleanup();
        $map = $this->invokePrivateMethod($instance, 'get_user_safety_flags_batch', [[42], 6, true]);

        $this->assertCount(1, $map[42]);
        $this->assertSame('recent_activity_meta', $map[42][0]['code']);
        $this->assertTrue($this->containsQuery($wpdb->queries, 'wp_usermeta'));
    }

    public function testProcessCleanupBatchDryRunIncrementsWouldDeleteAndKeepsTemp()
    {
        global $wpdb, $mock_options;
        $mock_options['intersoccer_fake_cleanup_schema_version'] = 4;
        $wpdb = $this->createCleanupBatchWpdb(
            [(object) ['id' => 10, 'email' => 'fake10@example.com']],
            [],
            []
        );

        $instance = $this->createCleanupInstanceWithTempLog();
        $result = $this->invokePrivateMethod($instance, 'process_cleanup_batch', [
            'cleanup-dry',
            [
                'batch_size' => 25,
                'dry_run' => true,
                'force_cleanup' => false,
                'is_new_cleanup' => true,
            ],
        ]);

        $this->assertSame(1, $result['progress']['would_delete']);
        $this->assertSame(0, $result['progress']['deleted']);
        $this->assertFalse($this->containsQuery($wpdb->queries, 'DELETE FROM wp_intersoccer_temp_fake_users'));
        $this->assertEmpty($GLOBALS['mock_deleted_users'] ?? []);
    }

    public function testProcessCleanupBatchSuccessfulDeleteRemovesTempAndAudits()
    {
        global $wpdb, $mock_options;
        $mock_options['intersoccer_fake_cleanup_schema_version'] = 4;
        $GLOBALS['mock_deleted_users'] = [];
        unset($GLOBALS['mock_wp_delete_user_return']);

        $wpdb = $this->createCleanupBatchWpdb(
            [(object) ['id' => 11, 'email' => 'fake11@example.com']],
            [],
            []
        );

        $instance = $this->createCleanupInstanceWithTempLog();
        $result = $this->invokePrivateMethod($instance, 'process_cleanup_batch', [
            'cleanup-ok',
            [
                'batch_size' => 25,
                'dry_run' => false,
                'force_cleanup' => false,
                'is_new_cleanup' => true,
            ],
        ]);

        $this->assertSame(1, $result['progress']['deleted']);
        $this->assertSame(0, $result['progress']['would_delete']);
        $this->assertSame(0, $result['progress']['delete_failed']);
        $this->assertSame([11], $GLOBALS['mock_deleted_users']);
        $this->assertTrue($this->containsQuery($wpdb->queries, 'DELETE FROM wp_intersoccer_temp_fake_users'));
        $this->assertTrue($this->containsQuery($wpdb->queries, 'INSERT INTO wp_intersoccer_cleanup_audit'));
    }

    public function testProcessCleanupBatchFailedDeleteKeepsTempAndIncrementsDeleteFailed()
    {
        global $wpdb, $mock_options;
        $mock_options['intersoccer_fake_cleanup_schema_version'] = 4;
        $GLOBALS['mock_deleted_users'] = [];
        $GLOBALS['mock_wp_delete_user_return'] = false;

        $wpdb = $this->createCleanupBatchWpdb(
            [(object) ['id' => 12, 'email' => 'fake12@example.com']],
            [],
            []
        );

        $instance = $this->createCleanupInstanceWithTempLog();
        $result = $this->invokePrivateMethod($instance, 'process_cleanup_batch', [
            'cleanup-fail',
            [
                'batch_size' => 25,
                'dry_run' => false,
                'force_cleanup' => false,
                'is_new_cleanup' => true,
            ],
        ]);

        $this->assertSame(0, $result['progress']['deleted']);
        $this->assertSame(1, $result['progress']['delete_failed']);
        $this->assertSame(1, $result['progress']['skipped']);
        $this->assertEmpty($GLOBALS['mock_deleted_users']);
        $this->assertFalse($this->containsQuery($wpdb->queries, 'DELETE FROM wp_intersoccer_temp_fake_users'));
        unset($GLOBALS['mock_wp_delete_user_return']);
    }

    public function testProcessCleanupBatchSafetySkipKeepsTempRow()
    {
        global $wpdb, $mock_options;
        $mock_options['intersoccer_fake_cleanup_schema_version'] = 4;
        $GLOBALS['mock_deleted_users'] = [];
        unset($GLOBALS['mock_wp_delete_user_return']);

        $wpdb = $this->createCleanupBatchWpdb(
            [(object) ['id' => 13, 'email' => 'real13@example.com']],
            ['13'],
            []
        );

        $instance = $this->createCleanupInstanceWithTempLog();
        $result = $this->invokePrivateMethod($instance, 'process_cleanup_batch', [
            'cleanup-review',
            [
                'batch_size' => 25,
                'dry_run' => false,
                'force_cleanup' => false,
                'is_new_cleanup' => true,
            ],
        ]);

        $this->assertSame(1, $result['progress']['reviewed']);
        $this->assertSame(0, $result['progress']['deleted']);
        $this->assertCount(1, $result['review_users']);
        $this->assertSame('customer_has_orders', $result['review_users'][0]['reasons'][0]['code']);
        $this->assertNotEmpty($wpdb->update_calls);
        $this->assertFalse($this->containsQuery($wpdb->queries, 'DELETE FROM wp_intersoccer_temp_fake_users'));
        $this->assertEmpty($GLOBALS['mock_deleted_users']);
    }

    /**
     * @param object[] $fakeUsers
     * @param array $orderUserIds
     * @param array $authorIds
     */
    private function createCleanupBatchWpdb(array $fakeUsers, array $orderUserIds, array $authorIds): FakeWpdb
    {
        $wpdb = new FakeWpdb();
        $wpdb->prefix = 'wp_';
        $wpdb->get_var_returns = [count($fakeUsers)];
        $regRows = array_map(static function ($u) {
            return (object) ['ID' => $u->id, 'user_registered' => '2025-07-01 12:00:00'];
        }, $fakeUsers);
        // Order: fake-user batch, activity usermeta (when force off), then registered map.
        $wpdb->get_results_returns = [$fakeUsers, [], $regRows];
        $wpdb->get_col_returns = [$orderUserIds, $authorIds];
        return $wpdb;
    }

    private function createCleanupInstanceWithTempLog(): InterSoccer_Fake_User_Cleanup
    {
        $instance = new InterSoccer_Fake_User_Cleanup();
        $reflection = new ReflectionClass($instance);
        $prop = $reflection->getProperty('log_file');
        $prop->setAccessible(true);
        $prop->setValue($instance, sys_get_temp_dir() . '/intersoccer-cleanup-test.log');
        return $instance;
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
    public $users = 'wp_users';
    public $usermeta = 'wp_usermeta';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $existingTables = [];
    public $fieldTypes = [];
    public $indexes = [];
    public $queries = [];
    public $replace_calls = [];
    public $update_calls = [];
    public $last_error = '';
    /** @var array Optional ordered return values for get_var() when not SHOW TABLES/FIELDS */
    public $get_var_returns = [];
    /** @var int Index into get_var_returns */
    private $get_var_index = 0;
    /** @var array Optional return value for get_results() when not SHOW INDEX */
    public $get_results_return = [];
    /** @var array Optional ordered return values for sequential get_results() calls */
    public $get_results_returns = [];
    /** @var int Index into get_results_returns */
    private $get_results_index = 0;
    /** @var array Optional ordered return values for get_col() */
    public $get_col_returns = [];
    /** @var int Index into get_col_returns */
    private $get_col_index = 0;

    public function get_charset_collate()
    {
        return 'utf8mb4_unicode_ci';
    }

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        if (empty($args)) {
            return $query;
        }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function () use (&$i, $args) {
            $v = $args[$i] ?? null;
            $i++;
            if (is_int($v) || $v === null) {
                return (string) (int) $v;
            }
            return "'" . addslashes((string) $v) . "'";
        }, $query);
    }

    public function get_var($sql)
    {
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/i", $sql, $matches)) {
            return in_array($matches[1], $this->existingTables, true) ? $matches[1] : null;
        }
        if (isset($this->get_var_returns[$this->get_var_index])) {
            $v = $this->get_var_returns[$this->get_var_index];
            $this->get_var_index++;
            return $v;
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
        $this->queries[] = $sql;
        if (preg_match("/SHOW INDEX FROM ([^ ]+)/i", $sql, $matches)) {
            $table = $matches[1];
            return $this->indexes[$table] ?? [];
        }
        if (!empty($this->get_results_returns) && isset($this->get_results_returns[$this->get_results_index])) {
            $result = $this->get_results_returns[$this->get_results_index];
            $this->get_results_index++;
            return $result;
        }
        if (!empty($this->get_results_return)) {
            return $this->get_results_return;
        }
        return [];
    }

    public function get_col($sql)
    {
        $this->queries[] = $sql;
        if (!empty($this->get_col_returns) && isset($this->get_col_returns[$this->get_col_index])) {
            $result = $this->get_col_returns[$this->get_col_index];
            $this->get_col_index++;
            return $result;
        }
        return [];
    }

    public function query($sql)
    {
        $this->queries[] = $sql;
        return true;
    }

    public function replace($table, $data, $format = null)
    {
        $this->replace_calls[] = array($table, $data);
        return true;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $this->update_calls[] = array($table, $data, $where);
        return 1;
    }
}
