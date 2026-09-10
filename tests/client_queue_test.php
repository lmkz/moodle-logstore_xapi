<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace logstore_xapi;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/admin/tool/log/store/xapi/lib.php');

/**
 * Tests for client-side xAPI statement queue helpers.
 *
 * @package   logstore_xapi
 * @copyright 2026 David Pesce <david.pesce@exputo.com>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class client_queue_test extends \advanced_testcase {
    /**
     * Return a valid client statement.
     *
     * @return array
     */
    private function statement(): array {
        return [
            'id' => 'client-statement-1',
            'actor' => ['mbox' => 'mailto:learner@example.test'],
            'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/completed'],
            'object' => ['id' => 'https://example.test/activity/1'],
        ];
    }

    /**
     * Valid statements are accepted and malformed statements are rejected.
     *
     * @return void
     */
    public function test_validate_client_statement(): void {
        $this->resetAfterTest();

        $error = null;
        $this->assertTrue(logstore_xapi_validate_client_statement($this->statement(), $error));
        $this->assertNull($error);

        $invalid = $this->statement();
        unset($invalid['verb']);
        $this->assertFalse(logstore_xapi_validate_client_statement($invalid, $error));
        $this->assertNotEmpty($error);
    }

    /**
     * Statements are queued once and duplicate statement IDs are ignored.
     *
     * @return void
     */
    public function test_queue_client_statement_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $context = \context_user::instance($user->id);
        $statement = $this->statement();
        $json = json_encode($statement);

        $first = logstore_xapi_queue_client_statement($statement, $json, $user->id, $context->id);
        $second = logstore_xapi_queue_client_statement($statement, $json, $user->id, $context->id);

        $this->assertTrue($first['queued']);
        $this->assertFalse($first['duplicate']);
        $this->assertFalse($second['queued']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame(1, $DB->count_records('logstore_xapi_client_log'));
    }

    /**
     * Failures retain a queue row and move it to the failed type.
     *
     * @return void
     */
    public function test_failed_client_statement_is_retained_for_retry(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $context = \context_user::instance($user->id);
        $statement = $this->statement();
        $json = json_encode($statement);
        $result = logstore_xapi_queue_client_statement($statement, $json, $user->id, $context->id);
        $record = $DB->get_record('logstore_xapi_client_log', ['id' => $result['id']], '*', MUST_EXIST);
        $record->errortype = XAPI_REPORT_ERRORTYPE_LRS;
        $record->response = 'test failure';

        logstore_xapi_update_client_event_failure($record);
        $updated = $DB->get_record('logstore_xapi_client_log', ['id' => $record->id], '*', MUST_EXIST);

        $this->assertSame(XAPI_IMPORT_TYPE_FAILED, (int)$updated->type);
        $this->assertSame(1, (int)$updated->attempts);
        $this->assertSame('test failure', $updated->response);
    }
}
