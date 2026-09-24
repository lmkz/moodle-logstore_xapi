<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Generic library for the logstore_xapi plugin.
 *
 * @package   logstore_xapi
 * @copyright Jerret Fowler <jerrett.fowler@gmail.com>
 *            Ryan Smith <https://www.linkedin.com/in/ryan-smith-uk/>
 *            David Pesce <david.pesce@exputo.com>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('XAPI_REPORT_ID_ERROR', 0);
define('XAPI_REPORT_ID_HISTORIC', 1);

// Client-side statement hardening.
//
// ajax/client_events.php is authenticated (require_login + sesskey), which
// stops CSRF and unauthenticated posts, but any logged-in user knows their
// own sesskey and can craft the POST by hand (curl, devtools, etc.). The
// statement JSON must therefore be treated as untrusted user input: size
// capped before decoding, shape capped after decoding, spoofable/LRS-owned
// fields rejected, and everything bound via the $DB API (no SQL string
// interpolation) so a hostile payload cannot become SQL injection. The same
// payload is later forwarded to the LRS and may surface in reports, so
// over-long strings and control characters are rejected here as well.

// Maximum raw JSON accepted for one client statement (typical H5P
// statements are a few KB; 64KB leaves ample headroom for context/result).
define('XAPI_CLIENT_STATEMENT_MAX_BYTES', 65536);
// Maximum nesting depth / total keys / string lengths, guarding against
// deeply-nested or "billion laughs"-style payloads and DB/TEXT abuse.
define('XAPI_CLIENT_STATEMENT_MAX_DEPTH', 20);
define('XAPI_CLIENT_STATEMENT_MAX_KEYS', 200);
define('XAPI_CLIENT_STATEMENT_MAX_STRING', 8192);
define('XAPI_CLIENT_STATEMENT_MAX_IRI', 2048);
define('XAPI_CLIENT_STATEMENT_MAX_ID', 255);

// Top-level xAPI statement properties a browser may send. 'stored' and
// 'authority' are deliberately absent: they are assigned by the LRS and a
// client supplying them is attempting to spoof LRS state.
define('XAPI_CLIENT_STATEMENT_TOP_LEVEL', [
    'id', 'actor', 'verb', 'object', 'result', 'context', 'timestamp', 'version', 'attachments',
]);

// Object types defined by the xAPI spec.
define('XAPI_CLIENT_STATEMENT_OBJECT_TYPES', [
    'Activity', 'Agent', 'Group', 'StatementRef', 'SubStatement',
]);

// Type constants.
define('XAPI_IMPORT_TYPE_LIVE', 0);
define('XAPI_IMPORT_TYPE_HISTORIC', 1);
define('XAPI_IMPORT_TYPE_FAILED', 2);

// Report source.
define('XAPI_REPORT_SOURCE_LOG', 'logstore_xapi_log');
define('XAPI_REPORT_SOURCE_FAILED', 'logstore_xapi_failed_log');
define('XAPI_REPORT_SOURCE_HISTORICAL', 'logstore_standard_log');

// Columns of the failed log that the error report may build filter options
// from. Used as an allow-list, since a column name cannot be bound as a query
// parameter and has to be interpolated into the SQL.
define('XAPI_REPORT_FILTER_COLUMNS', ['errortype', 'response']);

// Error types.
define('XAPI_REPORT_ERRORTYPE_NETWORK', 101);
define('XAPI_REPORT_ERRORTYPE_RECIPE', 400);
define('XAPI_REPORT_ERRORTYPE_AUTH', 401);
define('XAPI_REPORT_ERRORTYPE_LRS', 500);
define('XAPI_REPORT_ERRORTYPE_TRANSFORM', 10000); // This high number has been set to avoid conflicting with other error codes.

// Resend values.
define('XAPI_REPORT_RESEND_FALSE', 0);
define('XAPI_REPORT_RESEND_TRUE', 1);

// Default values on url parameters.
define('XAPI_REPORT_STARTING_PAGE', 0);
define('XAPI_REPORT_PERPAGE_DEFAULT', 40);
define('XAPI_REPORT_ONPAGE_DEFAULT', '');
define('XAPI_REPORT_EVENTCONTEXT_DEFAULT', '');
define('XAPI_REPORT_EVENTNAMES_DEFAULT', []);
define('XAPI_REPORT_ERROTYPE_DEFAULT', '0');
define('XAPI_REPORT_RESPONSE_DEFAULT', '0');
define('XAPI_REPORT_USERNAME_DEFAULT', '');
define('XAPI_REPORT_DATEFROM_DEFAULT', 0);
define('XAPI_REPORT_DATETO_DEFAULT', 0);

/**
 * Get all visible cohorts in the system.
 *
 * @return array Returns an array of all visible cohorts.
 */
function logstore_xapi_get_cohorts() {
    global $DB;
    $array = ["visible" => 1];
    $cohorts = $DB->get_records("cohort", $array);
    return $cohorts;
}

/**
 * Get the selected cohorts from the settings.
 *
 * Only returns IDs for cohorts that still exist and are visible, filtering out
 * any cohorts that have been deleted or made invisible since the selection was saved.
 *
 * @return array Returns an array of selected cohort ids.
 */
function logstore_xapi_get_selected_cohorts() {
    global $DB;

    $selected = get_config('logstore_xapi', 'cohorts');

    if (empty($selected)) {
        return [];
    }

    $ids = array_filter(array_map('intval', explode(',', $selected)));

    if (empty($ids)) {
        return [];
    }

    [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
    $inparams['visible'] = 1;
    $records = $DB->get_records_select('cohort', "id $insql AND visible = :visible", $inparams, '', 'id');

    return array_map('strval', array_keys($records));
}

/**
 * Return all members for a cohort
 *
 * @param array $cohortids array of cohort ids
 * @return array with cohort id keys containing arrays of user email addresses
 */
function logstore_xapi_get_cohort_members($cohortids) {
    global $DB;

    $members = [];

    foreach ($cohortids as $cohortid) {
        // Validate params.
        $cohort = $DB->get_record('cohort', ['id' => $cohortid], '*', MUST_EXIST);
        if (!empty($cohort)) {
            $sql = "SELECT u.*
                      FROM {user} u, {cohort_members} cm
                     WHERE u.id = cm.userid AND cm.cohortid = ?
                  ORDER BY lastname ASC, firstname ASC";
            $cohortmembers = $DB->get_records_sql($sql, [$cohort->id]);
            $members = array_merge($members, $cohortmembers);
        }
    }
    return $members;
}

/**
 * Get the selected cohorts from the settings.
 *
 * @return array Returns an array of user objects from cohorts and additional email addresses.
 */
function logstore_xapi_get_users_for_notifications() {
    // Get selected cohort users, will return a blank array if no cohorts are set.
    $cohorts = logstore_xapi_get_selected_cohorts();
    $users = logstore_xapi_get_cohort_members($cohorts);

    // Get the manually set email addresses from the config.
    $emailaddresses = get_config('logstore_xapi', 'send_additional_email_addresses');
    $emailaddresses = explode(",", $emailaddresses);
    foreach ($emailaddresses as $email) {
        // Remove whitespace from email addresses.
        $email = preg_replace('/\s+/', '', $email);
        if (validate_email($email)) {
            // If the email address is valid then add it to the list of users.
            $user = new stdClass();
            $user->email = $email;
            $users[] = $user;
        }
    }

    return $users;
}

/**
 * Gets the unique column values
 *
 * The column name is interpolated into SQL, since identifiers cannot be bound
 * as parameters. It is checked against a fixed allow-list so that a future
 * caller cannot turn this helper into an injection point.
 *
 * @param string $column One of the columns listed in XAPI_REPORT_FILTER_COLUMNS.
 * @return array
 * @throws coding_exception If the column is not one this helper supports.
 * @throws dml_exception
 */
function logstore_xapi_get_distinct_options_from_failed_table($column) {
    global $DB;

    if (!in_array($column, XAPI_REPORT_FILTER_COLUMNS, true)) {
        throw new coding_exception('Unsupported filter column: ' . $column);
    }

    $options = [0 => get_string('any')];
    $results = $DB->get_fieldset_select('logstore_xapi_failed_log', "DISTINCT $column", '');
    if ($results) {
        foreach ($results as $result) {
            $options[$result] = $result;
        }
    }
    return $options;
}

/**
 * Get the available context's from the logstore standard log table
 *
 * @return array
 * @throws dml_exception
 */
function logstore_xapi_get_logstore_standard_context_options() {
    global $DB;

    $options = [0 => get_string('any')];

    $sql = 'SELECT DISTINCT(contextid)
              FROM {logstore_standard_log} lssl
             WHERE EXISTS (SELECT 1
                             FROM {context} c
                            WHERE c.id = lssl.contextid)';
    $contextids = array_keys($DB->get_records_sql($sql));

    foreach ($contextids as $contextid) {
        $context = context::instance_by_id($contextid);
        $options[$context->id] = $context->get_context_name();
    }
    asort($options);

    return $options;
}

/**
 * Retrieves the available and enabled events for this plugin and outputs it into an array
 *
 * @return array
 */
function logstore_xapi_get_event_names_array() {

    if (!function_exists('\src\transformer\get_event_function_map')) {
        global $CFG;

        require_once($CFG->dirroot . '/admin/tool/log/store/xapi/src/transformer/get_event_function_map.php');
    }

    $eventnames = [];
    $eventfunctionmap = \src\transformer\get_event_function_map();
    foreach (array_keys($eventfunctionmap) as $eventname) {
        $eventnames[$eventname] = $eventname;
    }
    return $eventnames;
}

/**
 * Decode the json array stored in the response column. Will return false if json is invalid
 *
 * @param string $response JSON string response.
 * @return array|bool
 */
function logstore_xapi_decode_response($response) {
    $decode = json_decode($response, true);
    // Check JSON is valid.
    if (json_last_error() === JSON_ERROR_NONE) {
        return $decode;
    }
    return false;
}

/**
 * Generate the string for the info column in the report
 *
 * @param object $row
 * @return string
 * @throws coding_exception
 */
function logstore_xapi_get_info_string($row) {
    if (!empty($row->errortype)) {
        switch ($row->errortype) {
            case XAPI_REPORT_ERRORTYPE_NETWORK:
                return get_string('networkerror', 'logstore_xapi');
            case XAPI_REPORT_ERRORTYPE_RECIPE:
                // Recipe issue.
                return get_string('recipeerror', 'logstore_xapi');
            case XAPI_REPORT_ERRORTYPE_AUTH:
                // Unauthorised, could be an issue with xAPI credentials.
                return get_string('autherror', 'logstore_xapi');
            case XAPI_REPORT_ERRORTYPE_LRS:
                // The xAPI server error.
                return get_string('lrserror', 'logstore_xapi');
            case XAPI_REPORT_ERRORTYPE_TRANSFORM:
                // Transform error.
                return get_string('failedtransformresponse', 'logstore_xapi', $row->eventname);
            default:
                // Generic error catch all.
                return get_string('unknownerror', 'logstore_xapi', $row->errortype);
                break;
        }
    }
    return ''; // Return blank if no errortype captured.
}

/**
 * Get successful events.
 *
 * @param array $events An array of events.
 * @return array
 */
function logstore_xapi_get_successful_events($events) {
    $loadedevents = array_filter($events, function ($loadedevent) {
        return $loadedevent['loaded'] === true;
    });
    $successfulevents = array_map(function ($loadedevent) {
        return $loadedevent['event'];
    }, $loadedevents);
    return $successfulevents;
}

/**
 * Take event data and add to the sent log if it doesn't exist already.
 *
 * @param stdObj $event raw event data
 * @return void
 */
function logstore_xapi_add_event_to_sent_log($event) {
    global $DB;

    $row = $DB->get_record('logstore_xapi_sent_log', ['logstorestandardlogid' => $event->logstorestandardlogid]);
    if (empty($row)) {
        $newrow = new stdClass();
        $newrow->logstorestandardlogid = $event->logstorestandardlogid;
        $newrow->type = $event->type;
        $newrow->timecreated = time();
        $DB->insert_record('logstore_xapi_sent_log', $newrow);
    }
}

/**
 * Extract events from logstore_xapi_log or logstore_xapi_failed_log.
 *
 * @param int $limitnum limit number
 * @param int $log log source
 * @param int $type event type
 * @return array
 */
function logstore_xapi_extract_events($limitnum, $log, $type) {
    global $DB;

    $conditions = ["type" => $type];
    $sort = '';
    $fields = '*';
    $limitfrom = 0;

    $events = $DB->get_records($log, $conditions, $sort, $fields, $limitfrom, $limitnum);
    return $events;
}

/**
 * Get event ids.
 *
 * @param array $loadedevents raw events data
 * @return array
 */
function logstore_xapi_get_event_ids($loadedevents) {
    return array_map(function ($loadedevent) {
        return $loadedevent['event']->id;
    }, $loadedevents);
}

/**
 * Delete processed events.
 *
 * @param array $events raw events data
 * @return void
 */
function logstore_xapi_delete_processed_events($events) {
    global $DB;
    $eventids = logstore_xapi_get_event_ids($events);
    $DB->delete_records_list('logstore_xapi_log', 'id', $eventids);
}

/**
 * Log the number of events using mtrace.
 *
 * @param array $events raw events data
 * @return void
 */
function logstore_xapi_record_successful_events($events) {
    mtrace(count(logstore_xapi_get_successful_events($events)) . " " . get_string('successful_events', 'logstore_xapi'));
}

/**
 * Take successful events and save each using logstore_xapi_add_event_to_sent_log.
 *
 * @param array $events raw events data
 * @return void
 */
function logstore_xapi_save_sent_events(array $events) {
    $successfulevents = logstore_xapi_get_successful_events($events);
    foreach ($successfulevents as $event) {
        logstore_xapi_add_event_to_sent_log($event);
    }
}

/**
 * Get failed events as array.
 *
 * @param array $events An array of events.
 * @return array
 */
function logstore_xapi_get_failed_events($events) {
    $nonloadedevents = array_filter($events, function ($loadedevent) {
        return $loadedevent['loaded'] === false;
    });
    $failedevents = array_map(function ($nonloadedevent) {
        return $nonloadedevent['event'];
    }, $nonloadedevents);
    return $failedevents;
}

/**
 * Store failed events in logstore_xapi_failed_log.
 *
 * @param array $events An array of events.
 * @return void
 */
function logstore_xapi_store_failed_events($events) {
    global $DB;

    $failedevents = logstore_xapi_get_failed_events($events);
    $DB->insert_records('logstore_xapi_failed_log', $failedevents);
    mtrace(count($failedevents) . " " . get_string('failed_events', 'logstore_xapi'));
}

/**
 * determine the type from the initial base table
 *
 * @param string $table
 * @return int
 */
function logstore_xapi_get_type_from_table($table) {
    switch ($table) {
        case XAPI_REPORT_SOURCE_LOG:
            return XAPI_IMPORT_TYPE_LIVE;
        case XAPI_REPORT_SOURCE_FAILED:
            return XAPI_IMPORT_TYPE_FAILED;
        case XAPI_REPORT_SOURCE_HISTORICAL:
            return XAPI_IMPORT_TYPE_HISTORIC;
        default:
            return XAPI_IMPORT_TYPE_LIVE;
    }
}

/**
 * Check whether a value looks like an absolute IRI.
 *
 * Mirrors the RFC 3986 scheme grammar: a letter followed by letters, digits,
 * '+', '-' or '.', then a colon and at least one additional non-whitespace
 * character. It does not attempt full RFC 3987 parsing. Length is capped so
 * a hostile client cannot smuggle megabytes inside a single IRI field.
 *
 * @param mixed $value Value to check.
 * @return bool
 */
function logstore_xapi_is_valid_iri($value) {
    return is_string($value) &&
        strlen($value) <= XAPI_CLIENT_STATEMENT_MAX_IRI &&
        strpos($value, "\0") === false &&
        (bool) preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:[^\s]+$/', $value);
}

/**
 * Validate raw client statement JSON before it is decoded.
 *
 * Must run before json_decode so a single huge POST cannot exhaust memory.
 * Guards against the "SQL injection equivalent" here too: the payload is
 * never interpolated into SQL (all writes go through $DB->insert_record
 * with bound parameters), but an uncapped TEXT value is still a DoS and
 * truncation vector, so it is rejected up front.
 *
 * @param mixed $statementjson Raw POST value.
 * @param string|null $error Set to a validation error when invalid.
 * @return bool
 */
function logstore_xapi_validate_client_statement_json($statementjson, &$error = null) {
    $error = null;
    if (!is_string($statementjson) || $statementjson === '') {
        $error = 'The statement payload is required.';
        return false;
    }
    if (strpos($statementjson, "\0") !== false) {
        $error = 'The statement payload contains an invalid character.';
        return false;
    }
    if (strlen($statementjson) > XAPI_CLIENT_STATEMENT_MAX_BYTES) {
        $error = 'The statement payload exceeds the maximum allowed size.';
        return false;
    }
    return true;
}

/**
 * Recursively cap the shape of a decoded statement.
 *
 * Limits nesting depth, total key count, key names and string values. This
 * blocks deeply-nested decoding bombs, key-flooding, null-byte smuggling
 * and control-character log/report injection in one pass.
 *
 * @param mixed $node Current node.
 * @param string|null $error Set to a validation error when invalid.
 * @param int $depth Current depth.
 * @param int $keycount Running total of array keys seen.
 * @return bool
 */
function logstore_xapi_check_client_statement_shape($node, &$error = null, $depth = 0, &$keycount = 0) {
    if ($depth > XAPI_CLIENT_STATEMENT_MAX_DEPTH) {
        $error = 'The statement is nested too deeply.';
        return false;
    }
    if (is_array($node)) {
        $keycount += count($node);
        if ($keycount > XAPI_CLIENT_STATEMENT_MAX_KEYS) {
            $error = 'The statement contains too many fields.';
            return false;
        }
        foreach ($node as $key => $value) {
            if (!is_string($key) || $key === '' || strlen($key) > 128 ||
                    preg_match('/[\x00-\x1F\x7F]/', $key)) {
                $error = 'The statement contains an invalid field name.';
                return false;
            }
            if (!logstore_xapi_check_client_statement_shape($value, $error, $depth + 1, $keycount)) {
                return false;
            }
        }
        return true;
    }
    if (is_string($node)) {
        if (strlen($node) > XAPI_CLIENT_STATEMENT_MAX_STRING) {
            $error = 'The statement contains a value that is too long.';
            return false;
        }
        if (strpos($node, "\0") !== false ||
                preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $node)) {
            $error = 'The statement contains an invalid character.';
            return false;
        }
        return true;
    }
    if (is_null($node) || is_bool($node) || is_int($node) || is_float($node)) {
        if (is_float($node) && !is_finite($node)) {
            $error = 'The statement contains an invalid number.';
            return false;
        }
        return true;
    }
    $error = 'The statement contains an unsupported value.';
    return false;
}

/**
 * Validate an ISO 8601 timestamp as used by xAPI.
 *
 * @param mixed $value Value to check.
 * @return bool
 */
function logstore_xapi_is_valid_statement_timestamp($value) {
    if (!is_string($value) || strlen($value) > 64 || strpos($value, "\0") !== false) {
        return false;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $value)) {
        return false;
    }
    return strtotime($value) !== false;
}

/**
 * Validate the subset of an xAPI statement required by the client queue.
 *
 * The statement arrives from the browser, and any authenticated user can
 * forge it (sesskey proves the request came from their own session, not
 * that the H5P content produced it). Validation is therefore hostile-input
 * validation, not just well-formedness: size/shape caps, an allow-list of
 * top-level fields, rejection of LRS-owned fields (stored/authority),
 * IRI checks, and range checks on scores.
 *
 * Note: the actor is intentionally NOT trusted here. ajax/client_events.php
 * overwrites it with the authenticated user after validation.
 *
 * @param mixed $statement Decoded statement data.
 * @param string|null $error Set to a validation error when invalid.
 * @param string|null $statementjson Optional raw JSON for a size pre-check.
 * @return bool
 */
function logstore_xapi_validate_client_statement($statement, &$error = null, $statementjson = null) {
    $error = null;

    if ($statementjson !== null && !logstore_xapi_validate_client_statement_json($statementjson, $error)) {
        return false;
    }

    if (!is_array($statement)) {
        $error = 'The statement must be a JSON object.';
        return false;
    }

    // Unknown top-level members carry no meaning for the queue and only
    // enlarge the LRS payload / stored row, so reject them. LRS-owned
    // members are rejected explicitly below with a clearer message.
    foreach (array_keys($statement) as $key) {
        if ($key === 'stored' || $key === 'authority') {
            $error = 'The statement contains a field that only the LRS may set.';
            return false;
        }
        if (!in_array($key, XAPI_CLIENT_STATEMENT_TOP_LEVEL, true)) {
            $error = 'The statement contains an unsupported field.';
            return false;
        }
    }

    if (empty($statement['actor']) || !is_array($statement['actor'])) {
        $error = 'The statement actor is required.';
        return false;
    }

    if (empty($statement['verb']) || !is_array($statement['verb']) ||
            empty($statement['verb']['id']) || !logstore_xapi_is_valid_iri($statement['verb']['id'])) {
        $error = 'The statement verb must contain a valid IRI.';
        return false;
    }

    if (empty($statement['object']) || !is_array($statement['object'])) {
        $error = 'The statement object is required.';
        return false;
    }

    // Activity objects carry an IRI id. Statement references, sub-statements
    // and other xAPI object types identify themselves by objectType and may
    // carry a non-IRI id, so the IRI check only applies to plain activities.
    $objecttype = $statement['object']['objectType'] ?? null;
    if ($objecttype !== null &&
            (!is_string($objecttype) || !in_array($objecttype, XAPI_CLIENT_STATEMENT_OBJECT_TYPES, true))) {
        $error = 'The statement object type is not supported.';
        return false;
    }
    if (isset($statement['object']['id'])) {
        if (!is_string($statement['object']['id']) || $statement['object']['id'] === '' ||
                strlen($statement['object']['id']) > XAPI_CLIENT_STATEMENT_MAX_IRI) {
            $error = 'The statement object id must be a non-empty string.';
            return false;
        }
        if (($objecttype === null || $objecttype === 'Activity') &&
                !logstore_xapi_is_valid_iri($statement['object']['id'])) {
            $error = 'The statement object id must be a valid IRI.';
            return false;
        }
    } else if ($objecttype === null) {
        $error = 'The statement object must contain an id or objectType.';
        return false;
    }

    if (isset($statement['id'])) {
        if (!is_string($statement['id']) || $statement['id'] === '' ||
                strlen($statement['id']) > XAPI_CLIENT_STATEMENT_MAX_ID ||
                strpos($statement['id'], "\0") !== false ||
                preg_match('/[\x00-\x1F\x7F\s]/', $statement['id'])) {
            $error = 'The statement id must be a non-empty string.';
            return false;
        }
    }

    if (isset($statement['timestamp']) && !logstore_xapi_is_valid_statement_timestamp($statement['timestamp'])) {
        $error = 'The statement timestamp is not valid.';
        return false;
    }

    if (isset($statement['version'])) {
        if (!is_string($statement['version']) || strlen($statement['version']) > 16 ||
                !preg_match('/^1\.0\.\d+$/', $statement['version'])) {
            $error = 'The statement version is not supported.';
            return false;
        }
    }


    $keycount = 0;
    if (!logstore_xapi_check_client_statement_shape($statement, $error, 0, $keycount)) {
        return false;
    }

    return true;
}

/**
 * Queue a client-side xAPI statement unless it has already been queued or sent.
 *
 * Defence in depth: the caller (ajax/client_events.php) already validates,
 * but this re-checks sizes and scalar bindings so a future caller cannot
 * turn the queue into a truncation/SQL-injection point. All writes use
 * $DB->insert_record with bound parameters; no statement content is ever
 * interpolated into SQL.
 *
 * @param array $statement Decoded xAPI statement.
 * @param string $statementjson Original JSON representation.
 * @param int $userid Authenticated Moodle user id.
 * @param int $contextid Moodle context id.
 * @param int|null $courseid Optional course id.
 * @param string|null $ip Request IP address.
 * @return array Queue result.
 */
function logstore_xapi_queue_client_statement(array $statement, $statementjson, $userid, $contextid,
        $courseid = null, $ip = null) {
    global $DB;

    $validationerror = null;
    if (!logstore_xapi_validate_client_statement($statement, $validationerror, $statementjson)) {
        throw new coding_exception('Invalid client statement: ' . $validationerror);
    }

    $userid = (int)$userid;
    $contextid = (int)$contextid;
    if ($userid <= 0 || $contextid <= 0) {
        throw new coding_exception('Invalid user or context for client statement.');
    }

    $statementid = isset($statement['id']) ? (string)$statement['id'] : null;
    if ($statementid !== null && strlen($statementid) > XAPI_CLIENT_STATEMENT_MAX_ID) {
        throw new coding_exception('Invalid client statement id.');
    }

    // Bound to a char(45) column; keep only a valid IP (or nothing) so a
    // forged header cannot smuggle control characters into the log.
    $iprecord = null;
    if (is_string($ip) && $ip !== '') {
        $ip = trim(substr($ip, 0, 45));
        if (strpos($ip, "\0") === false && !preg_match('/[\x00-\x1F\x7F]/', $ip)) {
            $iprecord = $ip;
        }
    }

    $clientkey = hash('sha256', $statementid !== null ? 'id:' . $statementid : 'json:' . $statementjson);

    if ($DB->record_exists('logstore_xapi_client_log', ['clientkey' => $clientkey]) ||
            $DB->record_exists('logstore_xapi_client_sent', ['clientkey' => $clientkey])) {
        return ['queued' => false, 'duplicate' => true, 'id' => 0];
    }

    $record = (object) [
        'statement' => $statementjson,
        'clientkey' => $clientkey,
        'statementid' => $statementid,
        'userid' => $userid,
        'contextid' => $contextid,
        'courseid' => $courseid === null ? null : (int)$courseid,
        'timecreated' => time(),
        'ip' => $iprecord,
        'type' => XAPI_IMPORT_TYPE_LIVE,
        'attempts' => 0,
    ];

    try {
        $id = $DB->insert_record('logstore_xapi_client_log', $record);
    } catch (\dml_exception $exception) {
        // The unique client key also protects against two concurrent browser
        // requests for the same statement.
        if ($DB->record_exists('logstore_xapi_client_log', ['clientkey' => $clientkey]) ||
                $DB->record_exists('logstore_xapi_client_sent', ['clientkey' => $clientkey])) {
            return ['queued' => false, 'duplicate' => true, 'id' => 0];
        }
        throw $exception;
    }

    return [
        'queued' => true,
        'duplicate' => false,
        'id' => $id,
    ];
}

/**
 * Extract client statements awaiting processing.
 *
 * @param int $limitnum Maximum number of records.
 * @param int $type Client queue type.
 * @return array
 */
function logstore_xapi_extract_client_events($limitnum, $type) {
    global $DB;

    return $DB->get_records('logstore_xapi_client_log', ['type' => $type], 'id ASC', '*', 0, $limitnum);
}

/**
 * Add a successfully sent client statement to the idempotency log.
 *
 * @param stdClass $event Client queue record.
 * @return void
 */
function logstore_xapi_add_client_event_to_sent_log($event) {
    global $DB;

    if (!$DB->record_exists('logstore_xapi_client_sent', ['clientkey' => $event->clientkey])) {
        $DB->insert_record('logstore_xapi_client_sent', (object) [
            'clientkey' => $event->clientkey,
            'statementid' => $event->statementid,
            'userid' => $event->userid,
            'contextid' => $event->contextid,
            'timecreated' => time(),
        ]);
    }
}

/**
 * Mark a client statement as failed so the failed queue task can retry it.
 *
 * @param stdClass $event Client queue record with error information.
 * @return void
 */
function logstore_xapi_update_client_event_failure($event) {
    global $DB;

    $record = (object) [
        'id' => $event->id,
        'type' => XAPI_IMPORT_TYPE_FAILED,
        'attempts' => ((int)($event->attempts ?? 0)) + 1,
        'errortype' => $event->errortype ?? XAPI_REPORT_ERRORTYPE_LRS,
        'response' => $event->response ?? '',
    ];
    $DB->update_record('logstore_xapi_client_log', $record);
}

/**
 * Security checks contributed to the site security report.
 *
 * Called by \core\check\manager::get_security_checks().
 *
 * @return array of \core\check\check
 */
function logstore_xapi_security_checks() {
    return [
        new \logstore_xapi\check\ssl_verification(),
    ];
}

