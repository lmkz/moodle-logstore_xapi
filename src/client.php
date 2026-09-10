<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Client-side xAPI statement helpers.
 *
 * @package   logstore_xapi
 * @copyright 2026 David Pesce <david.pesce@exputo.com>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace logstore_xapi\client;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/autoload.php');
require_once(dirname(__DIR__) . '/lib.php');

/**
 * Build LRS loader configuration for client statements.
 *
 * @return array
 */
function get_loader_config(): array {
    return [
        'lrs_endpoint' => get_config('logstore_xapi', 'endpoint') ?: '',
        'lrs_username' => get_config('logstore_xapi', 'username') ?: '',
        'lrs_password' => get_config('logstore_xapi', 'password') ?: '',
        'lrs_max_batch_size' => get_config('logstore_xapi', 'maxbatchsize') ?: 30,
        'lrs_resend_failed_batches' => get_config('logstore_xapi', 'resendfailedbatches') ?: false,
        'lrs_ssl_verification' => get_config('logstore_xapi', 'sslverification') === false ? true :
            (bool)get_config('logstore_xapi', 'sslverification'),
        'lrs_ssl_cabundle' => get_config('logstore_xapi', 'sslcabundle') ?: '',
        'log_error' => function ($message = '') {
            mtrace($message);
        },
        'log_info' => function ($message = '') {
            mtrace($message);
        },
    ];
}

/**
 * Send a list of xAPI statements through the existing LRS loader.
 *
 * @param array $config Loader configuration.
 * @param array $statements xAPI statements.
 * @param array $records Queue records matching the statements.
 * @return array Results matching the records by numeric index.
 */
function send_statements(array $config, array $statements, array $records): array {
    $events = [];
    foreach ($statements as $index => $statement) {
        $events[] = [
            'event' => $records[$index],
            'statements' => [$statement],
            'transformed' => true,
        ];
    }

    $loaded = \src\loader\moodle_curl_lrs\load($config, $events);
    return array_map(function ($result) {
        return [
            'loaded' => $result['loaded'] === true,
            'errortype' => ($result['event']->errortype ?? 0) ?: XAPI_REPORT_ERRORTYPE_NETWORK,
            'response' => $result['event']->response ?? '',
        ];
    }, $loaded);
}

/**
 * Process a batch of client queue records.
 *
 * @param array $records Queue records.
 * @return array Processing summary with per-record delivery results.
 */
function process(array $records): array {
    global $DB;

    $summary = [
        'processed' => 0,
        'sent' => 0,
        'failed' => 0,
        'results' => [],
    ];

    if (empty($records)) {
        return $summary;
    }

    $statements = [];
    $recordmap = [];
    foreach ($records as $record) {
        $statement = json_decode($record->statement, true);
        $error = null;
        if (json_last_error() !== JSON_ERROR_NONE ||
                !\logstore_xapi_validate_client_statement($statement, $error)) {
            $record->errortype = XAPI_REPORT_ERRORTYPE_TRANSFORM;
            $record->response = $error ?: 'Stored client statement is not valid JSON.';
            \logstore_xapi_update_client_event_failure($record);
            $summary['processed']++;
            $summary['failed']++;
            $summary['results'][] = [
                'id' => $record->id,
                'success' => false,
                'errortype' => XAPI_REPORT_ERRORTYPE_TRANSFORM,
                'response' => $record->response,
            ];
            continue;
        }
        $recordmap[] = $record;
        $statements[] = $statement;
    }

    if (empty($statements)) {
        return $summary;
    }

    $results = send_statements(get_loader_config(), $statements, $recordmap);
    foreach ($results as $index => $result) {
        $record = $recordmap[$index];
        $summary['processed']++;
        if ($result['loaded']) {
            \logstore_xapi_add_client_event_to_sent_log($record);
            $DB->delete_records('logstore_xapi_client_log', ['id' => $record->id]);
            $summary['sent']++;
        } else {
            $record->errortype = $result['errortype'];
            $record->response = $result['response'];
            \logstore_xapi_update_client_event_failure($record);
            $summary['failed']++;
        }
        $summary['results'][] = [
            'id' => $record->id,
            'success' => $result['loaded'],
            'errortype' => $result['errortype'],
            'response' => $result['response'],
        ];
    }

    return $summary;
}
