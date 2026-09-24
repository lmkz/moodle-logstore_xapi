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
 * Canonical client-side (H5P) xAPI verbs.
 *
 * Keys are short names, values are full ADL verb IRIs. Mirrors
 * H5P.XAPIEvent.allowedXAPIVerbs.
 *
 * @return array Short name => full verb IRI.
 */
function get_client_verb_map(): array {
    $base = 'http://adlnet.gov/expapi/verbs/';
    $shorts = [
        'answered',
        'asked',
        'attempted',
        'attended',
        'commented',
        'completed',
        'exited',
        'experienced',
        'failed',
        'imported',
        'initialized',
        'interacted',
        'launched',
        'mastered',
        'passed',
        'preferred',
        'progressed',
        'registered',
        'responded',
        'resumed',
        'scored',
        'shared',
        'suspended',
        'terminated',
        'voided',
        'downloaded',
        'copied',
        'accessed-reuse',
        'accessed-embed',
        'accessed-copyright',
    ];
    $map = [];
    foreach ($shorts as $short) {
        $map[$short] = $base . $short;
    }
    return $map;
}

/**
 * Get the short names of enabled client verbs.
 *
 * A `false` config (never saved) means all verbs enabled for backwards
 * compatibility. An empty string means all disabled.
 *
 * @return array Enabled short names.
 */
function get_enabled_client_verbs(): array {
    $map = get_client_verb_map();
    $raw = get_config('logstore_xapi', 'clientverbs');
    if ($raw === false) {
        return array_keys($map);
    }
    if (is_array($raw)) {
        $selected = array_keys(array_filter($raw));
    } else {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return [];
        }
        $selected = array_map('trim', explode(',', $raw));
    }
    return array_values(array_intersect($selected, array_keys($map)));
}

/**
 * Get the full IRIs of enabled client verbs, for browser pre-filtering.
 *
 * @return array Enabled verb IRIs.
 */
function get_enabled_client_verb_ids(): array {
    $map = get_client_verb_map();
    $enabled = get_enabled_client_verbs();
    $ids = [];
    foreach ($enabled as $short) {
        if (isset($map[$short])) {
            $ids[] = $map[$short];
        }
    }
    return $ids;
}

/**
 * Whether statements with unknown (non-H5P-list) verbs should be allowed.
 *
 * Separate setting so custom verbs fail open by default without forcing
 * admins to enumerate them.
 *
 * @return bool
 */
function get_clientverbs_allow_unknown(): bool {
    $val = get_config('logstore_xapi', 'clientverbs_allow_unknown');
    if ($val === false) {
        return true;
    }
    return (bool)$val;
}

/**
 * Check whether a client verb IRI is enabled.
 *
 * @param string $verbid Full verb IRI from the statement.
 * @return bool
 */
function is_client_verb_enabled($verbid): bool {
    $verbid = trim((string)$verbid);
    if ($verbid === '') {
        return false;
    }
    $map = get_client_verb_map();
    $flipped = array_flip($map);
    if (isset($flipped[$verbid])) {
        return in_array($flipped[$verbid], get_enabled_client_verbs(), true);
    }
    return get_clientverbs_allow_unknown();
}

/**
 * Build an xAPI actor for an authenticated user, mirroring the plugin's
 * actor identification settings.
 *
 * @param \stdClass $user Moodle user.
 * @param array $config Actor configuration.
 * @return array
 */
function get_actor_for_user(\stdClass $user, array $config): array {
    $actor = [];

    if (!empty($config['send_name'])) {
        $actor['name'] = \src\transformer\utils\get_full_name($user);
    }

    $hasvalidemail = filter_var($user->email ?? '', FILTER_VALIDATE_EMAIL);
    if (!empty($config['send_mbox']) && $hasvalidemail) {
        $actor['mbox'] = 'mailto:' . $user->email;
        return $actor;
    }

    $homepage = !empty($config['account_homepage']) ? $config['account_homepage'] : ($config['app_url'] ?? '');
    if (!empty($config['send_username'])) {
        $actor['account'] = [
            'homePage' => $homepage,
            'name' => $user->username ?? '',
        ];
        return $actor;
    }

    $actor['account'] = [
        'homePage' => $config['app_url'] ?? '',
        'name' => (string)($user->id ?? ''),
    ];
    return $actor;
}

/**
 * Acquire the client queue lock.
 *
 * @param string $resource Lock resource identifier.
 * @param int $timeout Time in seconds to wait for the lock.
 * @return \core\lock\lock|false The lock when acquired, false otherwise.
 */
function get_queue_lock(string $resource, int $timeout) {
    $lockfactory = \core\lock\lock_config::get_lock_factory('logstore_xapi');
    return $lockfactory->get_lock($resource, $timeout);
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
        'filtered' => 0,
        'results' => [],
    ];

    if (empty($records)) {
        return $summary;
    }

    $statements = [];
    $recordmap = [];
    foreach ($records as $record) {
        // A concurrent worker may have delivered this record between extraction
        // and processing, so only act on records that are still queued. Deleted
        // records were sent successfully elsewhere and can be counted as sent.
        if (!$DB->record_exists('logstore_xapi_client_log', ['id' => $record->id])) {
            $summary['processed']++;
            $summary['sent']++;
            $summary['results'][] = [
                'id' => $record->id,
                'success' => true,
                'errortype' => 0,
                'response' => '',
            ];
            continue;
        }

        // Re-validate stored payloads: a row written before this hardening,
        // or via a future caller, must not be able to exhaust the worker.
        $error = null;
        $statement = null;
        if (\logstore_xapi_validate_client_statement_json($record->statement ?? '', $error)) {
            $statement = json_decode($record->statement, true, XAPI_CLIENT_STATEMENT_MAX_DEPTH + 1);
        }
        if (json_last_error() !== JSON_ERROR_NONE ||
                !\logstore_xapi_validate_client_statement($statement, $error, $record->statement ?? '')) {
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
        if (!is_client_verb_enabled($statement['verb']['id'] ?? '')) {
            // Verb disabled after the statement was queued: drop silently
            // without sending to the LRS.
            $DB->delete_records('logstore_xapi_client_log', ['id' => $record->id]);
            $summary['processed']++;
            $summary['filtered']++;
            $summary['results'][] = [
                'id' => $record->id,
                'success' => true,
                'filtered' => true,
                'errortype' => 0,
                'response' => '',
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

/**
 * Process a queued batch of client statements, protected by the queue lock.
 *
 * The lock is held while records are extracted and delivered so that two
 * scheduled task runs (for example on different cron hosts) cannot send the
 * same batch twice.
 *
 * @param int $batchsize Maximum number of records to process.
 * @param int $type Client queue type.
 * @return array Processing summary with per-record delivery results.
 */
function process_queued(int $batchsize, int $type): array {
    $lock = get_queue_lock('client_queue_' . ((int)$type), 5);
    if (!$lock) {
        return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'filtered' => 0, 'skipped' => true,
            'results' => []];
    }

    try {
        $records = \logstore_xapi_extract_client_events($batchsize, $type);
        return process($records);
    } finally {
        $lock->release();
    }
}

/**
 * Process given client records immediately, protected by the queue lock.
 *
 * @param array $records Queue records.
 * @return array Processing summary with per-record delivery results.
 */
function process_records(array $records): array {
    $lock = get_queue_lock('client_queue_' . XAPI_IMPORT_TYPE_LIVE, 5);
    if (!$lock) {
        return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'filtered' => 0, 'skipped' => true,
            'results' => []];
    }

    try {
        return process($records);
    } finally {
        $lock->release();
    }
}
