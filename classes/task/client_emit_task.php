<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace logstore_xapi\task;

defined('MOODLE_INTERNAL') || die();

require_once(dirname(__DIR__, 2) . '/lib.php');

use logstore_xapi\client\queue_processor;

/**
 * Emit queued client-side statements to the LRS.
 *
 * @package   logstore_xapi
 * @copyright 2026 David Pesce <david.pesce@exputo.com>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client_emit_task extends \core\task\scheduled_task {
    /**
     * Get a descriptive task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskclientemit', 'logstore_xapi');
    }

    /**
     * Process one client statement batch.
     *
     * @return void
     */
    public function execute() {
        $batchsize = (int)get_config('logstore_xapi', 'maxbatchsize');
        if ($batchsize <= 0) {
            $batchsize = 30;
        }

        $live = queue_processor::process_queued($batchsize, XAPI_IMPORT_TYPE_LIVE);
        mtrace('logstore_xapi client_emit_task: ' . $live['sent'] . ' sent, ' .
            $live['failed'] . ' failed, ' . ($live['filtered'] ?? 0) . ' filtered from live queue.');

        // Failed records are retried by the same task. This keeps the minimal
        // implementation from requiring a second queue or failed-task class.
        $failed = queue_processor::process_queued($batchsize, XAPI_IMPORT_TYPE_FAILED);
        mtrace('logstore_xapi client_emit_task: ' . $failed['sent'] . ' sent, ' .
            $failed['failed'] . ' failed, ' . ($failed['filtered'] ?? 0) . ' filtered from retry queue.');
    }
}
