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
 * Library of functions for database manipulation.
 *
 * Other main libraries:
 * - weblib.php - functions that produce web output
 * - moodlelib.php - general-purpose Moodle functions
 *
 * @package    core
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * The maximum number of courses in a category, overridden by $CFG->maxcoursesincategory.
 *
 * @deprecated since Moodle 6.0, use {@see get_max_courses_in_category()} instead.
 */
define('MAX_COURSES_IN_CATEGORY', 10000);

/**
  * The maximum number of course categories
  *
  * @deprecated since Moodle 6.0, nothing derives the sortorder of a category from this value
  * any more, see {@see fix_course_sortorder()}.
  */
define('MAX_COURSE_CATEGORIES', 10000);

/**
 * Number of seconds to wait before updating lastaccess information in DB.
 *
 * We allow overwrites from config.php, useful to ensure coherence in performance
 * tests results.
 *
 * Note: For web service requests in the external_tokens field, we use a different constant
 * webservice::TOKEN_LASTACCESS_UPDATE_SECS.
 */
if (!defined('LASTACCESS_UPDATE_SECS')) {
    define('LASTACCESS_UPDATE_SECS', 60);
}
/**
 * The constant value when we use the search option.
 */
define('USER_SEARCH_STARTS_WITH', 0);
define('USER_SEARCH_CONTAINS', 1);
define('USER_SEARCH_EXACT_MATCH', 2);

/**
 * Returns $user object of the main admin user
 *
 * @static stdClass $mainadmin
 * @return stdClass|false {user} record from DB, false if not found
 */
function get_admin() {
    global $CFG, $DB;

    static $mainadmin = null;
    static $prevadmins = null;

    if (empty($CFG->siteadmins)) {
        // Should not happen on an ordinary site.
        // It does however happen during unit tests.
        return false;
    }

    if (isset($mainadmin) and $prevadmins === $CFG->siteadmins) {
        return clone($mainadmin);
    }

    $mainadmin = null;

    foreach (explode(',', $CFG->siteadmins) as $id) {
        if ($user = $DB->get_record('user', array('id'=>$id, 'deleted'=>0))) {
            $mainadmin = $user;
            break;
        }
    }

    if ($mainadmin) {
        $prevadmins = $CFG->siteadmins;
        return clone($mainadmin);
    } else {
        // this should not happen
        return false;
    }
}

/**
 * Returns list of all admins, using 1 DB query
 *
 * @return array
 */
function get_admins() {
    global $DB, $CFG;

    if (empty($CFG->siteadmins)) {  // Should not happen on an ordinary site
        return array();
    }

    $sql = "SELECT u.*
              FROM {user} u
             WHERE u.deleted = 0 AND u.id IN ($CFG->siteadmins)";

    // We want the same order as in $CFG->siteadmins.
    $records = $DB->get_records_sql($sql);
    $admins = array();
    foreach (explode(',', $CFG->siteadmins) as $id) {
        $id = (int)$id;
        if (!isset($records[$id])) {
            // User does not exist, this should not happen.
            continue;
        }
        $admins[$records[$id]->id] = $records[$id];
    }

    return $admins;
}

/**
 * Search through course users
 *
 * If $coursid specifies the site course then this function searches
 * through all undeleted and confirmed users
 *
 * @global object
 * @uses SITEID
 * @uses SQL_PARAMS_NAMED
 * @uses CONTEXT_COURSE
 * @param int $courseid The course in question.
 * @param int $groupid The group in question.
 * @param string $searchtext The string to search for
 * @param string $sort A field to sort by
 * @param array $exceptions A list of IDs to ignore, eg 2,4,5,8,9,10
 * @return array
 */
function search_users($courseid, $groupid, $searchtext, $sort='', ?array $exceptions=null) {
    global $DB;

    $fullname  = $DB->sql_fullname('u.firstname', 'u.lastname');

    if (!empty($exceptions)) {
        list($exceptions, $params) = $DB->get_in_or_equal($exceptions, SQL_PARAMS_NAMED, 'ex', false);
        $except = "AND u.id $exceptions";
    } else {
        $except = "";
        $params = array();
    }

    if (!empty($sort)) {
        $order = "ORDER BY $sort";
    } else {
        $order = "";
    }

    $select = "u.deleted = 0 AND u.confirmed = 1 AND (".$DB->sql_like($fullname, ':search1', false)." OR ".$DB->sql_like('u.email', ':search2', false).")";
    $params['search1'] = "%$searchtext%";
    $params['search2'] = "%$searchtext%";

    if (!$courseid or $courseid == SITEID) {
        $sql = "SELECT u.id, u.firstname, u.lastname, u.email
                  FROM {user} u
                 WHERE $select
                       $except
                $order";
        return $DB->get_records_sql($sql, $params);

    } else {
        if ($groupid) {
            $sql = "SELECT u.id, u.firstname, u.lastname, u.email
                      FROM {user} u
                      JOIN {groups_members} gm ON gm.userid = u.id
                     WHERE $select AND gm.groupid = :groupid
                           $except
                     $order";
            $params['groupid'] = $groupid;
            return $DB->get_records_sql($sql, $params);

        } else {
            $context = context_course::instance($courseid);

            // We want to query both the current context and parent contexts.
            list($relatedctxsql, $relatedctxparams) = $DB->get_in_or_equal($context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'relatedctx');

            $sql = "SELECT u.id, u.firstname, u.lastname, u.email
                      FROM {user} u
                      JOIN {role_assignments} ra ON ra.userid = u.id
                     WHERE $select AND ra.contextid $relatedctxsql
                           $except
                    $order";
            $params = array_merge($params, $relatedctxparams);
            return $DB->get_records_sql($sql, $params);
        }
    }
}

/**
 * Returns SQL used to search through user table to find users (in a query
 * which may also join and apply other conditions).
 *
 * You can combine this SQL with an existing query by adding 'AND $sql' to the
 * WHERE clause of your query (where $sql is the first element in the array
 * returned by this function), and merging in the $params array to the parameters
 * of your query (where $params is the second element). Your query should use
 * named parameters such as :param, rather than the question mark style.
 *
 * There are examples of basic usage in the unit test for this function.
 *
 * @param string $search the text to search for (empty string = find all)
 * @param string $u the table alias for the user table in the query being
 *     built. May be ''.
 * @param int $searchtype If 0(default): searches at start, 1: searches in the middle of names
 *      2: search exact match.
 * @param array $extrafields Array of extra user fields to include in search, must be prefixed with table alias if they are not in
 *     the user table.
 * @param array $exclude Array of user ids to exclude (empty = don't exclude)
 * @param array $includeonly If specified, only returns users that have ids
 *     incldued in this array (empty = don't restrict)
 * @return array an array with two elements, a fragment of SQL to go in the
 *     where clause the query, and an associative array containing any required
 *     parameters (using named placeholders).
 */
function users_search_sql(string $search, string $u = 'u', int $searchtype = USER_SEARCH_STARTS_WITH, array $extrafields = [],
        ?array $exclude = null, ?array $includeonly = null): array {
    global $DB, $CFG;
    $params = array();
    $tests = array();

    if ($u) {
        $u .= '.';
    }

    if ($search) {
        $conditions = array(
            $DB->sql_fullname($u . 'firstname', $u . 'lastname'),
            $conditions[] = $u . 'lastname'
        );
        foreach ($extrafields as $field) {
            // Add the table alias for the user table if the field doesn't already have an alias.
            $conditions[] = strpos($field, '.') !== false ? $field : $u . $field;
        }
        switch ($searchtype) {
            case USER_SEARCH_STARTS_WITH:
                // Put a field LIKE 'search%' condition on each field.
                $searchparam = $search . '%';
                break;
            case USER_SEARCH_CONTAINS:
                // Put a field LIKE '$search%' condition on each field.
                $searchparam = '%' . $search . '%';
                break;
            case USER_SEARCH_EXACT_MATCH:
                // Match exact the $search string.
                $searchparam = $search;
                break;
        }
        $i = 0;
        foreach ($conditions as $key => $condition) {
            $conditions[$key] = $DB->sql_like($condition, ":con{$i}00", false, false);
            if ($searchtype === USER_SEARCH_EXACT_MATCH) {
                $conditions[$key] = "$condition = :con{$i}00";
            }
            $params["con{$i}00"] = $searchparam;
            $i++;
        }
        $tests[] = '(' . implode(' OR ', $conditions) . ')';
    }

    // Add some additional sensible conditions.
    $tests[] = $u . "id <> :guestid";
    $params['guestid'] = $CFG->siteguest;
    $tests[] = $u . 'deleted = 0';
    $tests[] = $u . 'confirmed = 1';

    // If we are being asked to exclude any users, do that.
    if (!empty($exclude)) {
        list($usertest, $userparams) = $DB->get_in_or_equal($exclude, SQL_PARAMS_NAMED, 'ex', false);
        $tests[] = $u . 'id ' . $usertest;
        $params = array_merge($params, $userparams);
    }

    // If we are validating a set list of userids, add an id IN (...) test.
    if (!empty($includeonly)) {
        list($usertest, $userparams) = $DB->get_in_or_equal($includeonly, SQL_PARAMS_NAMED, 'val');
        $tests[] = $u . 'id ' . $usertest;
        $params = array_merge($params, $userparams);
    }

    // In case there are no tests, add one result (this makes it easier to combine
    // this with an existing query as you can always add AND $sql).
    if (empty($tests)) {
        $tests[] = '1 = 1';
    }

    // Combing the conditions and return.
    return array(implode(' AND ', $tests), $params);
}


/**
 * This function generates the standard ORDER BY clause for use when generating
 * lists of users. If you don't have a reason to use a different order, then
 * you should use this method to generate the order when displaying lists of users.
 *
 * If the optional $search parameter is passed, then exact matches to the search
 * will be sorted first. For example, suppose you have two users 'Al Zebra' and
 * 'Alan Aardvark'. The default sort is Alan, then Al. If, however, you search for
 * 'Al', then Al will be listed first. (With two users, this is not a big deal,
 * but with thousands of users, it is essential.)
 *
 * The list of fields scanned for exact matches are:
 *  - firstname
 *  - lastname
 *  - $DB->sql_fullname
 *  - those returned by \core_user\fields::get_identity_fields or those included in $customfieldmappings
 *
 * If named parameters are used (which is the default, and highly recommended),
 * then the parameter names are like :usersortexactN, where N is an int.
 *
 * The simplest possible example use is:
 * list($sort, $params) = users_order_by_sql();
 * $sql = 'SELECT * FROM {users} ORDER BY ' . $sort;
 *
 * A more complex example, showing that this sort can be combined with other sorts:
 * list($sort, $sortparams) = users_order_by_sql('u');
 * $sql = "SELECT g.id AS groupid, gg.groupingid, u.id AS userid, u.firstname, u.lastname, u.idnumber, u.username
 *           FROM {groups} g
 *      LEFT JOIN {groupings_groups} gg ON g.id = gg.groupid
 *      LEFT JOIN {groups_members} gm ON g.id = gm.groupid
 *      LEFT JOIN {user} u ON gm.userid = u.id
 *          WHERE g.courseid = :courseid $groupwhere $groupingwhere
 *       ORDER BY g.name, $sort";
 * $params += $sortparams;
 *
 * An example showing the use of $search:
 * list($sort, $sortparams) = users_order_by_sql('u', $search, $this->get_context());
 * $order = ' ORDER BY ' . $sort;
 * $params += $sortparams;
 * $availableusers = $DB->get_records_sql($fields . $sql . $order, $params, $page*$perpage, $perpage);
 *
 * @param string $usertablealias (optional) any table prefix for the {users} table. E.g. 'u'.
 * @param string $search (optional) a current search string. If given,
 *      any exact matches to this string will be sorted first.
 * @param context|null $context the context we are in. Used by \core_user\fields::get_identity_fields.
 *      Defaults to $PAGE->context.
 * @param array $customfieldmappings associative array of mappings for custom fields returned by \core_user\fields::get_sql.
 * @return array with two elements:
 *      string SQL fragment to use in the ORDER BY clause. For example, "firstname, lastname".
 *      array of parameters used in the SQL fragment. If $search is not given, this is guaranteed to be an empty array.
 */
function users_order_by_sql(string $usertablealias = '', ?string $search = null, ?context $context = null,
        array $customfieldmappings = []) {
    global $DB, $PAGE;

    if ($usertablealias) {
        $tableprefix = $usertablealias . '.';
    } else {
        $tableprefix = '';
    }

    $sort = "{$tableprefix}lastname, {$tableprefix}firstname, {$tableprefix}id";
    $params = array();

    if (!$search) {
        return array($sort, $params);
    }

    if (!$context) {
        $context = $PAGE->context;
    }

    $exactconditions = array();

    $exactconditions[] = $DB->sql_fullname($tableprefix . 'firstname', $tableprefix  . 'lastname') .
            ' = :usersortexact';
    $params['usersortexact'] = $search;

    if ($customfieldmappings) {
        $fieldstocheck = array_merge([$tableprefix . 'firstname', $tableprefix . 'lastname'], array_values($customfieldmappings));
    } else {
        $fieldstocheck = array_merge(['firstname', 'lastname'], \core_user\fields::get_identity_fields($context, false));
        $fieldstocheck = array_map(function($field) use ($tableprefix) {
            return $tableprefix . $field;
        }, $fieldstocheck);
    }

    foreach ($fieldstocheck as $key => $field) {
        $exactconditions[] = 'LOWER(' . $field . ') = LOWER(:usersortfield' . $key . ')';
        $params['usersortfield' . $key] = $search;
    }

    $sort = 'CASE WHEN ' . implode(' OR ', $exactconditions) .
            ' THEN 0 ELSE 1 END, ' . $sort;

    return array($sort, $params);
}

/**
 * Returns a subset of users
 *
 * @global object
 * @uses DEBUG_DEVELOPER
 * @uses SQL_PARAMS_NAMED
 * @param bool $get If false then only a count of the records is returned
 * @param string $search A simple string to search for
 * @param bool $confirmed A switch to allow/disallow unconfirmed users
 * @param array $exceptions A list of IDs to ignore, eg 2,4,5,8,9,10
 * @param string $sort A SQL snippet for the sorting criteria to use
 * @param string $firstinitial Users whose first name starts with $firstinitial
 * @param string $lastinitial Users whose last name starts with $lastinitial
 * @param string $page The page or records to return
 * @param string $recordsperpage The number of records to return per page
 * @param string $fields A comma separated list of fields to be returned from the chosen table.
 * @return array|int|bool  {@link $USER} records unless get is false in which case the integer count of the records found is returned.
 *                        False is returned if an error is encountered.
 */
function get_users($get=true, $search='', $confirmed=false, ?array $exceptions=null, $sort='firstname ASC',
                   $firstinitial='', $lastinitial='', $page='', $recordsperpage='', $fields='*', $extraselect='', ?array $extraparams=null) {
    global $DB, $CFG;

    if ($get && !$recordsperpage) {
        debugging('Call to get_users with $get = true no $recordsperpage limit. ' .
                'On large installations, this will probably cause an out of memory error. ' .
                'Please think again and change your code so that it does not try to ' .
                'load so much data into memory.', DEBUG_DEVELOPER);
    }

    $fullname  = $DB->sql_fullname();

    $select = " id <> :guestid AND deleted = 0";
    $params = array('guestid'=>$CFG->siteguest);

    if (!empty($search)){
        $search = trim($search);
        $select .= " AND (".$DB->sql_like($fullname, ':search1', false)." OR ".$DB->sql_like('email', ':search2', false)." OR username = :search3)";
        $params['search1'] = "%$search%";
        $params['search2'] = "%$search%";
        $params['search3'] = "$search";
    }

    if ($confirmed) {
        $select .= " AND confirmed = 1";
    }

    if ($exceptions) {
        list($exceptions, $eparams) = $DB->get_in_or_equal($exceptions, SQL_PARAMS_NAMED, 'ex', false);
        $params = $params + $eparams;
        $select .= " AND id $exceptions";
    }

    if ($firstinitial) {
        $select .= " AND ".$DB->sql_like('firstname', ':fni', false, false);
        $params['fni'] = "$firstinitial%";
    }
    if ($lastinitial) {
        $select .= " AND ".$DB->sql_like('lastname', ':lni', false, false);
        $params['lni'] = "$lastinitial%";
    }

    if ($extraselect) {
        $select .= " AND $extraselect";
        $params = $params + (array)$extraparams;
    }

    if ($get) {
        return $DB->get_records_select('user', $select, $params, $sort, $fields, $page, $recordsperpage);
    } else {
        return $DB->count_records_select('user', $select, $params);
    }
}


/**
 * Return filtered (if provided) list of users in site, except guest and deleted users.
 *
 * @param string $sort An SQL field to sort by
 * @param string $dir The sort direction ASC|DESC
 * @param int $page The page or records to return
 * @param int $recordsperpage The number of records to return per page
 * @param string $search A simple string to search for
 * @param string $firstinitial Users whose first name starts with $firstinitial
 * @param string $lastinitial Users whose last name starts with $lastinitial
 * @param string $extraselect An additional SQL select statement to append to the query
 * @param array $extraparams Additional parameters to use for the above $extraselect
 * @param stdClass $extracontext If specified, will include user 'extra fields'
 *   as appropriate for current user and given context
 * @return array Array of {@link $USER} records
 */
function get_users_listing($sort='lastaccess', $dir='ASC', $page=0, $recordsperpage=0,
                           $search='', $firstinitial='', $lastinitial='', $extraselect='',
                           ?array $extraparams=null, $extracontext = null) {
    global $DB, $CFG;

    $fullname  = $DB->sql_fullname();

    $select = "deleted <> 1 AND u.id <> :guestid";
    $params = array('guestid' => $CFG->siteguest);

    if (!empty($search)) {
        $search = trim($search);
        $select .= " AND (". $DB->sql_like($fullname, ':search1', false, false).
                   " OR ". $DB->sql_like('email', ':search2', false, false).
                   " OR username = :search3)";
        $params['search1'] = "%$search%";
        $params['search2'] = "%$search%";
        $params['search3'] = "$search";
    }

    if ($firstinitial) {
        $select .= " AND ". $DB->sql_like('firstname', ':fni', false, false);
        $params['fni'] = "$firstinitial%";
    }
    if ($lastinitial) {
        $select .= " AND ". $DB->sql_like('lastname', ':lni', false, false);
        $params['lni'] = "$lastinitial%";
    }

    if ($extraselect) {
        // The extra WHERE clause may refer to the 'id' column which can now be ambiguous because we
        // changed the query to include joins, so replace any 'id' that is on its own (no alias)
        // with 'u.id'.
        $extraselect = preg_replace('~([ =]|^)id([ =]|$)~', '$1u.id$2', $extraselect);
        $select .= " AND $extraselect";
        $params = $params + (array)$extraparams;
    }

    // If a context is specified, get extra user fields that the current user
    // is supposed to see, otherwise just get the name fields.
    $userfields = \core_user\fields::for_name();
    if ($extracontext) {
        $userfields->with_identity($extracontext, true);
    }

    $userfields->excluding('id');
    $userfields->including('username', 'email', 'city', 'country', 'lastaccess', 'confirmed', 'mnethostid', 'suspended');
    ['selects' => $selects, 'joins' => $joins, 'params' => $joinparams, 'mappings' => $mappings] =
            (array)$userfields->get_sql('u', true);

    if ($sort) {
        $orderbymap = $mappings;
        $orderbymap['default'] = 'lastaccess';
        $sort = get_safe_orderby($orderbymap, $sort, $dir);
    }

    // warning: will return UNCONFIRMED USERS
    return $DB->get_records_sql("SELECT u.id $selects
                                   FROM {user} u
                                        $joins
                                  WHERE $select
                                  $sort", array_merge($params, $joinparams), $page, $recordsperpage);

}


/**
 * Full list of users that have confirmed their accounts.
 *
 * @global object
 * @return array of unconfirmed users
 */
function get_users_confirmed() {
    global $DB, $CFG;
    return $DB->get_records_sql("SELECT *
                                   FROM {user}
                                  WHERE confirmed = 1 AND deleted = 0 AND id <> ?", array($CFG->siteguest));
}


/// OTHER SITE AND COURSE FUNCTIONS /////////////////////////////////////////////


/**
 * Returns $course object of the top-level site.
 *
 * @return object A {@link $COURSE} object for the site, exception if not found
 */
function get_site() {
    global $SITE, $DB;

    if (!empty($SITE->id)) {   // We already have a global to use, so return that
        return $SITE;
    }

    if ($course = $DB->get_record('course', array('category'=>0))) {
        return $course;
    } else {
        // course table exists, but the site is not there,
        // unfortunately there is no automatic way to recover
        throw new moodle_exception('nosite', 'error');
    }
}

/**
 * Gets a course object from database. If the course id corresponds to an
 * already-loaded $COURSE or $SITE object, then the loaded object will be used,
 * saving a database query.
 *
 * If it reuses an existing object, by default the object will be cloned. This
 * means you can modify the object safely without affecting other code.
 *
 * @param int $courseid Course id
 * @param bool $clone If true (default), makes a clone of the record
 * @return stdClass A course object
 * @throws dml_exception If not found in database
 */
function get_course($courseid, $clone = true) {
    global $DB, $COURSE, $SITE;
    if (!empty($COURSE->id) && $COURSE->id == $courseid) {
        return $clone ? clone($COURSE) : $COURSE;
    } else if (!empty($SITE->id) && $SITE->id == $courseid) {
        return $clone ? clone($SITE) : $SITE;
    } else {
        return $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
    }
}

/**
 * Returns list of courses, for whole site, or category
 *
 * Returns list of courses, for whole site, or category
 * Important: Using c.* for fields is extremely expensive because
 *            we are using distinct. You almost _NEVER_ need all the fields
 *            in such a large SELECT
 *
 * Consider using core_course_category::get_courses()
 * or core_course_category::search_courses() instead since they use caching.
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_COURSE
 * @param string|int $categoryid Either a category id or 'all' for everything
 * @param string $sort A field and direction to sort by
 * @param string $fields The additional fields to return (note that "id, category, visible" are always present)
 * @return array Array of courses
 */
function get_courses($categoryid="all", $sort="c.sortorder ASC", $fields="c.*") {

    global $USER, $CFG, $DB;

    $params = array();

    if ($categoryid !== "all" && is_numeric($categoryid)) {
        $categoryselect = "WHERE c.category = :catid";
        $params['catid'] = $categoryid;
    } else {
        $categoryselect = "";
    }

    if (empty($sort)) {
        $sortstatement = "";
    } else {
        $sortstatement = "ORDER BY $sort";
    }

    $visiblecourses = array();

    $ccselect = ', ' . context_helper::get_preload_record_columns_sql('ctx');
    $ccjoin = "LEFT JOIN {context} ctx ON (ctx.instanceid = c.id AND ctx.contextlevel = :contextlevel)";
    $params['contextlevel'] = CONTEXT_COURSE;

    // The fields "id, category, visible" are required in the subsequent loop and must always be present.
    if ($fields !== 'c.*') {
        $fieldarray = array_merge(
            // Split fields on comma + zero or more whitespace, merge with required fields.
            preg_split('/,\s*/', $fields), [
                'c.id',
                'c.category',
                'c.visible',
            ]
        );
        $fields = implode(',', array_unique($fieldarray));
    }

    $sql = "SELECT $fields $ccselect
              FROM {course} c
           $ccjoin
              $categoryselect
              $sortstatement";

    // pull out all course matching the cat
    if ($courses = $DB->get_records_sql($sql, $params)) {

        // loop throught them
        foreach ($courses as $course) {
            context_helper::preload_from_record($course);
            if (core_course_category::can_view_course_info($course)) {
                $visiblecourses [$course->id] = $course;
            }
        }
    }
    return $visiblecourses;
}

/**
 * A list of courses that match a search
 *
 * @global object
 * @global object
 * @param array $searchterms An array of search criteria
 * @param string $sort A field and direction to sort by
 * @param int $page The page number to get
 * @param int $recordsperpage The number of records per page
 * @param int $totalcount Passed in by reference.
 * @param array $requiredcapabilities Extra list of capabilities used to filter courses
 * @param array $searchcond additional search conditions, for example ['c.enablecompletion = :p1']
 * @param array $params named parameters for additional search conditions, for example ['p1' => 1]
 * @return stdClass[] {@link $COURSE} records
 */
function get_courses_search($searchterms, $sort, $page, $recordsperpage, &$totalcount,
                            $requiredcapabilities = array(), $searchcond = [], $params = []) {
    global $CFG, $DB;

    if ($DB->sql_regex_supported()) {
        $REGEXP    = $DB->sql_regex(true);
        $NOTREGEXP = $DB->sql_regex(false);
    }

    $i = 0;

    $concat = $DB->sql_concat("COALESCE(c.summary, '')", "' '", 'c.fullname', "' '", 'c.idnumber', "' '", 'c.shortname');

    foreach ($searchterms as $searchterm) {
        $i++;

        // Initially we aren't going to perform NOT LIKE searches, only MSSQL
        // will use it to simulate the "-" operator with LIKE clause.
        $NOT = false;

        // Under MSSQL, trim the + and - operators and perform
        // simpler LIKE (or NOT LIKE) queries.
        if (!$DB->sql_regex_supported()) {
            if (substr($searchterm, 0, 1) == '-') {
                $NOT = true;
            }
            $searchterm = trim($searchterm, '+-');
        }

        // TODO: +- may not work for non latin languages

        if (substr($searchterm,0,1) == '+') {
            $searchterm = trim($searchterm, '+-');
            $searchterm = preg_quote($searchterm, '|');
            $searchcond[] = "$concat $REGEXP :ss$i";
            $params['ss'.$i] = "(^|[^a-zA-Z0-9])$searchterm([^a-zA-Z0-9]|$)";

        } else if ((substr($searchterm,0,1) == "-") && (core_text::strlen($searchterm) > 1)) {
            $searchterm = trim($searchterm, '+-');
            $searchterm = preg_quote($searchterm, '|');
            $searchcond[] = "$concat $NOTREGEXP :ss$i";
            $params['ss'.$i] = "(^|[^a-zA-Z0-9])$searchterm([^a-zA-Z0-9]|$)";

        } else {
            $searchcond[] = $DB->sql_like($concat,":ss$i", false, true, $NOT);
            $params['ss'.$i] = "%$searchterm%";
        }
    }

    if (empty($searchcond)) {
        $searchcond = array('1 = 1');
    }

    $searchcond = implode(" AND ", $searchcond);

    $courses = array();
    $c = 0; // counts how many visible courses we've seen

    // Tiki pagination
    $limitfrom = $page * $recordsperpage;
    $limitto   = $limitfrom + $recordsperpage;

    $ccselect = ', ' . context_helper::get_preload_record_columns_sql('ctx');
    $ccjoin = "LEFT JOIN {context} ctx ON (ctx.instanceid = c.id AND ctx.contextlevel = :contextlevel)";
    $params['contextlevel'] = CONTEXT_COURSE;

    $sql = "SELECT c.* $ccselect
              FROM {course} c
           $ccjoin
             WHERE $searchcond AND c.id <> ".SITEID."
          ORDER BY $sort";

    $mycourses = enrol_get_my_courses();
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $course) {
        // Preload contexts only for hidden courses or courses we need to return.
        context_helper::preload_from_record($course);
        $coursecontext = context_course::instance($course->id);
        if (!array_key_exists($course->id, $mycourses) && !core_course_category::can_view_course_info($course)) {
            continue;
        }
        if (!empty($requiredcapabilities)) {
            if (!has_all_capabilities($requiredcapabilities, $coursecontext)) {
                continue;
            }
        }
        // Don't exit this loop till the end
        // we need to count all the visible courses
        // to update $totalcount
        if ($c >= $limitfrom && $c < $limitto) {
            $courses[$course->id] = $course;
        }
        $c++;
    }
    $rs->close();

    // our caller expects 2 bits of data - our return
    // array, and an updated $totalcount
    $totalcount = $c;
    return $courses;
}

/**
 * Returns free course sortorder values in the given category, in ascending order.
 *
 * The courses are kept stacked against the top of that range, which keeps the space in front of
 * them free, so a new course goes there without shifting anything. Once that space runs out the
 * course is appended instead and {@see fix_course_sortorder()} repairs any duplicate it causes.
 *
 * @param int $categoryid the id of the category
 * @param int $count the number of the sortorder values to return
 * @return int[]
 */
function get_free_course_sortorders($categoryid, $count = 1) {
    global $DB;

    $catsortorder = (int) $DB->get_field('course_categories', 'sortorder', ['id' => $categoryid], MUST_EXIST);
    $sortorderlimit = $catsortorder + get_max_courses_in_category();

    $sql = 'SELECT MIN(sortorder) AS minsortorder, MAX(sortorder) AS maxsortorder
              FROM {course}
             WHERE category = ?';
    $bounds = $DB->get_record_sql($sql, [$categoryid]);
    $minsortorder = (int) $bounds->minsortorder;

    // Courses with a broken sortorder do not eat up the free space.
    $maxsortorder = min((int) $bounds->maxsortorder, $sortorderlimit);

    if ($minsortorder <= $catsortorder) {
        // No courses yet, or all of them are broken, so start at the top of the range.
        $firstfree = $sortorderlimit - $count;
    } else if ($minsortorder - $count >= $catsortorder + 1) {
        // There is enough free space in front of the first course of the category.
        $firstfree = $minsortorder - $count;
    } else {
        // Behind the last course, fix_course_sortorder() sorts out the duplicates if there is
        // not enough free space there either.
        $firstfree = $maxsortorder + 1;
    }

    return range($firstfree, $firstfree + $count - 1);
}

/**
 * Fixes course category and course sortorder, also verifies category and course parents and paths.
 * (circular references are not fixed)
 *
 * Puts the categories in depth first order and the courses of each of them inside its range.
 *
 * The sortorders are never compacted, so only the broken records are written: the categories that
 * are too close to their predecessor, and the courses that are out of the range of their category
 * or share their sortorder with another course. Gaps are always valid.
 *
 * @uses SITEID
 * @return void
 */
function fix_course_sortorder() {
    global $DB;

    // If there are any changes made to courses or categories we will trigger
    // the cache events to purge all cached courses/categories data.
    $cacheevents = [];

    // Minimal distance between two adjacent categories.
    $sortorderstep = get_max_courses_in_category();

    // All the categories, the records are reused for the course verification.
    $allcats = $DB->get_records(
        'course_categories',
        null,
        'sortorder, id',
        'id, sortorder, parent, depth, path, coursecount'
    );

    // The unplaced categories belong behind every placed one, with room to increment.
    $unplacedsortorder = 1;
    foreach ($allcats as $cat) {
        $unplacedsortorder = max($unplacedsortorder, (int) $cat->sortorder + 1);
    }

    $topcats    = [];
    $brokencats = [];
    foreach ($allcats as $cat) {
        $sortorder = (int) $cat->sortorder;
        if (empty($sortorder)) {
            $sortorder = $unplacedsortorder;
        }
        if (!$cat->parent) {
            while (isset($topcats[$sortorder])) {
                $sortorder++;
            }
            $topcats[$sortorder] = $cat;
            continue;
        }
        if (!isset($allcats[$cat->parent])) {
            $brokencats[] = $cat;
            continue;
        }
        if (!isset($allcats[$cat->parent]->children)) {
            $allcats[$cat->parent]->children = [];
        }
        while (isset($allcats[$cat->parent]->children[$sortorder])) {
            $sortorder++;
        }
        $allcats[$cat->parent]->children[$sortorder] = $cat;
    }

    // Add broken cats to category tree, they are visited after the categories that keep their place.
    if ($brokencats) {
        foreach ($brokencats as $cat) {
            $sortorder = (int) $cat->sortorder;
            if (empty($sortorder)) {
                $sortorder = $unplacedsortorder;
            }
            while (isset($topcats[$sortorder])) {
                $sortorder++;
            }
            $topcats[$sortorder] = $cat;
        }
        $brokencats = [];
    }

    // The children are keyed by the sortorder, but the unplaced ones were fetched first.
    ksort($topcats);
    foreach ($allcats as $cat) {
        if (isset($cat->children)) {
            ksort($cat->children);
        }
    }

    // Walk the tree and find out what has to change.
    $fixcontexts = [];
    $changecats = _fix_course_cats($topcats, $fixcontexts);
    if ($changecats) {
        $cacheevents['changesincoursecat'] = true;
        foreach ($changecats as $changecat) {
            $cat = $changecat['category'];
            $DB->update_record('course_categories', $cat, true);
            if (!empty($changecat['delta'])) {
                // The courses follow their category, the broken ones stay where they are.
                $oldsortorder = $cat->sortorder - $changecat['delta'];
                $DB->execute(
                    'UPDATE {course}
                                 SET sortorder = sortorder + :delta
                               WHERE category = :category
                                 AND sortorder > :minsortorder
                                 AND sortorder <= :maxsortorder',
                    [
                        'delta' => $changecat['delta'],
                        'category' => $cat->id,
                        'minsortorder' => $oldsortorder,
                        'maxsortorder' => $oldsortorder + $sortorderstep,
                    ]
                );
                $cacheevents['changesincourse'] = true;
            }
        }
        unset($changecats);
    }

    // Detect if there are "multiple" frontpage courses and fix them if needed.
    $frontcourses = $DB->get_records('course', ['category' => 0], 'id', 'id, sortorder');
    if (count($frontcourses) > 1) {
        if (isset($frontcourses[SITEID])) {
            $frontcourse = $frontcourses[SITEID];
            unset($frontcourses[SITEID]);
        } else {
            $frontcourse = array_shift($frontcourses);
        }
        $defaultcat = reset($topcats);
        foreach ($frontcourses as $course) {
            $DB->set_field('course', 'category', $defaultcat->id, ['id' => $course->id]);
            $context = context_course::instance($course->id);
            $fixcontexts[$context->id] = $context;
            $cacheevents['changesincourse'] = true;
        }
        unset($frontcourses);
    } else {
        $frontcourse = reset($frontcourses);
    }

    // Now fix the paths and depths in context table if needed.
    if ($fixcontexts) {
        foreach ($fixcontexts as $fixcontext) {
            $fixcontext->reset_paths(false);
        }
        context_helper::build_all_paths(false);
        unset($fixcontexts);
        $cacheevents['changesincourse'] = true;
        $cacheevents['changesincoursecat'] = true;
    }

    // Release memory.
    unset($topcats);
    unset($fixcontexts);

    // Fix frontpage course sortorder.
    if ($frontcourse && (int) $frontcourse->sortorder != 1) {
        $DB->set_field('course', 'sortorder', 1, ['id' => $frontcourse->id]);
        $cacheevents['changesincourse'] = true;
    }

    // One aggregated query for all the courses: the count, the duplicates and the range per category.
    $sql = "SELECT category,
                   COUNT(*) AS coursecount,
                   COUNT(DISTINCT sortorder) AS distinctcount,
                   MIN(sortorder) AS minsortorder,
                   MAX(sortorder) AS maxsortorder
              FROM {course}
          GROUP BY category";
    $categorycourses = $DB->get_records_sql($sql);

    // Categories holding more courses than they have room for.
    $fullcategories = [];

    // Categories which contain a course with a duplicated or out of range sortorder.
    $fixcategories = [];

    foreach ($allcats as $cat) {
        $catsortorder = (int) $cat->sortorder;
        if (empty($categorycourses[$cat->id])) {
            $coursecount = 0;
            $distinctcount = 0;
        } else {
            $summary = $categorycourses[$cat->id];
            $coursecount = (int) $summary->coursecount;
            $distinctcount = (int) $summary->distinctcount;
        }

        // Now fix the course counts in category records if needed.
        if ((int) $cat->coursecount !== $coursecount) {
            $DB->set_field('course_categories', 'coursecount', $coursecount, ['id' => $cat->id]);
            $cacheevents['changesincoursecat'] = true;
            if ($coursecount >= $sortorderstep) {
                $fullcategories[] = $cat->id;
            }
        }

        // Now make sure that sortorders in course table are within the category sortorder ranges
        // and are not shared by two courses of the same category.
        if (
            $coursecount && (
                $distinctcount !== $coursecount
                || (int) $summary->minsortorder <= $catsortorder
                || (int) $summary->maxsortorder > $catsortorder + $sortorderstep
            )
        ) {
            $fixcategories[$cat->id] = $catsortorder;
        }
    }
    unset($allcats);

    if (!empty($fullcategories)) {
        $str = implode(', ', $fullcategories);
        debugging("The number of courses (category id: $str) has reached max number of courses " .
            "in a category (" . $sortorderstep . "). It will cause a sorting performance issue. " .
            "Please set higher value for \$CFG->maxcoursesincategory in config.php. " .
            "See tracker issues: MDL-25669 and MDL-69573", DEBUG_DEVELOPER);
    }

    // Fix the course sortorders in the problematic categories only.
    foreach ($fixcategories as $categoryid => $catsortorder) {
        $courses = $DB->get_records('course', ['category' => $categoryid], 'sortorder ASC, id DESC', 'id, sortorder');
        $sortorderlimit = $catsortorder + $sortorderstep;

        // The sortorders already taken in the category range, and the courses that need a new one.
        $occupied = [];
        $fixcourses = [];
        $minsortorder = $catsortorder;
        $lowestgood = $sortorderlimit;
        foreach ($courses as $course) {
            $coursesortorder = (int) $course->sortorder;
            if ($coursesortorder > $minsortorder && $coursesortorder <= $sortorderlimit) {
                // Gaps between the courses of a category are allowed.
                $occupied[$coursesortorder] = true;
                $minsortorder = $coursesortorder;
                $lowestgood = min($lowestgood, $coursesortorder);
            } else {
                $fixcourses[] = $course;
            }
        }
        unset($courses);

        // Where to start looking, the courses keep the order they came back in.
        $fixcount = count($fixcourses);
        if (!$occupied) {
            // None are in the range any more, the category was moved. Stack them against its top.
            $minsortorder = $sortorderlimit - 1 - $fixcount;
        } else if ($lowestgood - $fixcount > $catsortorder) {
            // There is room in front of the courses, where the next new course goes as well.
            $minsortorder = $lowestgood - $fixcount - 1;
        }
        // Otherwise there is no free space in front, so they go behind the last course.

        foreach ($fixcourses as $course) {
            // Give it the next sortorder that is still free.
            $freesortorder = $minsortorder + 1;
            while ($freesortorder <= $sortorderlimit && isset($occupied[$freesortorder])) {
                $freesortorder++;
            }
            if ($freesortorder > $sortorderlimit) {
                // More courses than get_max_courses_in_category().
                break;
            }
            $occupied[$freesortorder] = true;
            $DB->set_field('course', 'sortorder', $freesortorder, ['id' => $course->id]);
            $cacheevents['changesincourse'] = true;
        }
        unset($fixcourses);
    }
    unset($fixcategories);

    // Advise all caches that need to be rebuilt.
    foreach (array_keys($cacheevents) as $event) {
        cache_helper::purge_by_event($event);
    }
}

/**
 * Internal category verification function, do not use directly!
 *
 * Walks the category tree and collects the categories whose sortorder has to change.
 *
 * A category keeps its sortorder unless it is too close to the previous one, so a change that
 * only leaves a gap behind, such as deleting a category, rewrites nothing here.
 *
 * Nothing is written, the records are updated in place and returned so that the caller can read
 * the resulting sortorder from them and write them.
 *
 * @param array $children the records to visit, their subcategories are in the children property, keyed by sortorder
 * @param array $fixcontexts the contexts of the categories whose path changed are added to it
 * @return array the categories to write, keyed by id, each the record and the delta for its courses, null if they stay
 */
function _fix_course_cats($children, &$fixcontexts) {
    $sortorderstep = get_max_courses_in_category();

    // Room for a few more categories at the same place.
    $extraroom = $sortorderstep * 100;
    $changes = [];

    // Where the walk stands. The values only move forward and are never restored, so a single set
    // of them is enough and only the position in the tree is kept per level of the stack.
    $sortorder = $sortorderstep;
    $unplacedbefore = false;
    $incascade = false;
    $stack = [
        [
            'categories' => $children,
            'parent' => 0,
            'depth' => 1,
            'path' => '',
        ],
    ];

    while ($stack) {
        $level = array_key_last($stack);

        // Take the first category left on this level and drop it, so that the next round finds
        // the one behind it.
        $firstkey = array_key_first($stack[$level]['categories']);
        if ($firstkey === null) {
            array_pop($stack);
            continue;
        }
        $cat = $stack[$level]['categories'][$firstkey];
        unset($stack[$level]['categories'][$firstkey]);

        ['parent' => $parent, 'depth' => $depth, 'path' => $path] = $stack[$level];

        $update = false;
        $delta = null;
        if ($parent != $cat->parent || $depth != $cat->depth || $path . '/' . $cat->id != $cat->path) {
            $cat->parent = $parent;
            $cat->depth  = $depth;
            $cat->path   = $path . '/' . $cat->id;
            $update = true;

            // Make sure context caches are rebuilt and dirty contexts marked.
            $context = context_coursecat::instance($cat->id);
            $fixcontexts[$context->id] = $context;
        }
        $catsortorder = (int) $cat->sortorder;
        $unplaced = empty($catsortorder);
        if ($incascade) {
            if ($catsortorder < $sortorder) {
                $sortorder += $extraroom;
            } else {
                // Far enough ahead, so the cascade stops here.
                $incascade = false;
            }
        }
        if ($catsortorder < $sortorder) {
            // Too close to the previous category, or not placed in the tree yet (sortorder 0).
            if ($unplacedbefore && !$unplaced) {
                // A category was just added here, so renumber the categories behind it only once.
                $sortorder += $extraroom;
                $incascade = true;
            }
            if (!$unplaced) {
                // The courses follow their category, the unplaced ones stay where they are.
                $delta = $sortorder - $catsortorder;
            }
            $cat->sortorder = $sortorder;
            $update = true;
        }
        if ($update) {
            $changes[$cat->id] = ['category' => $cat, 'delta' => $delta];
        }
        $sortorder = $cat->sortorder + $sortorderstep;
        $unplacedbefore = $unplaced;

        if (isset($cat->children)) {
            $stack[] = [
                'categories' => $cat->children,
                'parent' => $cat->id,
                'depth' => $depth + 1,
                'path' => $cat->path,
            ];
        }
    }
    return $changes;
}

/**
 * List of remote courses that a user has access to via MNET.
 * Works only on the IDP
 *
 * @global object
 * @global object
 * @param int @userid The user id to get remote courses for
 * @return array Array of {@link $COURSE} of course objects
 */
function get_my_remotecourses($userid=0) {
    global $DB, $USER;

    if (empty($userid)) {
        $userid = $USER->id;
    }

    // We can not use SELECT DISTINCT + text field (summary) because of MS SQL, subselect used therefore.
    $sql = "SELECT c.id, c.remoteid, c.shortname, c.fullname,
                   c.hostid, c.summary, c.summaryformat, c.categoryname AS cat_name,
                   h.name AS hostname
              FROM {mnetservice_enrol_courses} c
              JOIN (SELECT DISTINCT hostid, remotecourseid
                      FROM {mnetservice_enrol_enrolments}
                     WHERE userid = ?
                   ) e ON (e.hostid = c.hostid AND e.remotecourseid = c.remoteid)
              JOIN {mnet_host} h ON h.id = c.hostid";

    return $DB->get_records_sql($sql, array($userid));
}

/**
 * List of remote hosts that a user has access to via MNET.
 * Works on the SP
 *
 * @global object
 * @global object
 * @return array|bool Array of host objects or false
 */
function get_my_remotehosts() {
    global $CFG, $USER;

    if ($USER->mnethostid == $CFG->mnet_localhost_id) {
        return false; // Return nothing on the IDP
    }
    if (!empty($USER->mnet_foreign_host_array) && is_array($USER->mnet_foreign_host_array)) {
        return $USER->mnet_foreign_host_array;
    }
    return false;
}


/**
 * Returns a menu of all available scales from the site as well as the given course
 *
 * @global object
 * @param int $courseid The id of the course as found in the 'course' table.
 * @return array
 */
function get_scales_menu($courseid=0) {
    global $DB;

    $sql = "SELECT id, name, courseid
              FROM {scale}
             WHERE courseid = 0 or courseid = ?
          ORDER BY courseid ASC, name ASC";
    $params = array($courseid);
    $scales = array();
    $results = $DB->get_records_sql($sql, $params);
    foreach ($results as $index => $record) {
        $context = empty($record->courseid) ? context_system::instance() : context_course::instance($record->courseid);
        $scales[$index] = format_string($record->name, false, ["context" => $context]);
    }
    // Format: [id => 'scale name'].
    return $scales;
}

/**
 * Increment standard revision field.
 *
 * The revision are based on current time and are incrementing.
 * There is a protection for runaway revisions, it may not go further than
 * one hour into future.
 *
 * The field has to be XMLDB_TYPE_INTEGER with size 10.
 *
 * @param string $table
 * @param string $field name of the field containing revision
 * @param string $select use empty string when updating all records
 * @param array $params optional select parameters
 */
function increment_revision_number($table, $field, $select, ?array $params = null) {
    global $DB;

    $now = time();
    $sql = "UPDATE {{$table}}
                   SET $field = (CASE
                       WHEN $field IS NULL THEN $now
                       WHEN $field < $now THEN $now
                       WHEN $field > $now + 3600 THEN $now
                       ELSE $field + 1 END)";
    if ($select) {
        $sql = $sql . " WHERE $select";
    }
    $DB->execute($sql, $params);
}


/// MODULE FUNCTIONS /////////////////////////////////////////////////

/**
 * Just gets a raw list of all modules in a course
 *
 * @global object
 * @param int $courseid The id of the course as found in the 'course' table.
 * @return array|false
 */
function get_course_mods($courseid) {
    global $DB;

    if (empty($courseid)) {
        return false; // avoid warnings
    }

    return $DB->get_records_sql("SELECT cm.*, m.name as modname
                                   FROM {modules} m, {course_modules} cm
                                  WHERE cm.course = ? AND cm.module = m.id AND m.visible = 1",
                                array($courseid)); // no disabled mods
}


/**
 * Given an id of a course module, finds the coursemodule description
 *
 * Please note that this function performs 1-2 DB queries. When possible use cached
 * course modinfo. For example get_fast_modinfo($courseorid)->get_cm($cmid)
 * See also {@link cm_info::get_course_module_record()}
 *
 * @global object
 * @param string $modulename name of module type, eg. resource, assignment,... (optional, slower and less safe if not specified)
 * @param int $cmid course module id (id in course_modules table)
 * @param int $courseid optional course id for extra validation
 * @param bool $sectionnum include relative section number (0,1,2 ...)
 * @param int $strictness IGNORE_MISSING means compatible mode, false returned if record not found, debug message if more found;
 *                        IGNORE_MULTIPLE means return first, ignore multiple records found(not recommended);
 *                        MUST_EXIST means throw exception if no record or multiple records found
 * @return stdClass|false
 */
function get_coursemodule_from_id($modulename, $cmid, $courseid=0, $sectionnum=false, $strictness=IGNORE_MISSING) {
    global $DB;

    $params = array('cmid'=>$cmid);

    if (!$modulename) {
        if (!$modulename = $DB->get_field_sql("SELECT md.name
                                                 FROM {modules} md
                                                 JOIN {course_modules} cm ON cm.module = md.id
                                                WHERE cm.id = :cmid", $params, $strictness)) {
            return false;
        }
    } else {
        if (!core_component::is_valid_plugin_name('mod', $modulename)) {
            throw new coding_exception('Invalid modulename parameter');
        }
    }

    $params['modulename'] = $modulename;

    $courseselect = "";
    $sectionfield = "";
    $sectionjoin  = "";

    if ($courseid) {
        $courseselect = "AND cm.course = :courseid";
        $params['courseid'] = $courseid;
    }

    if ($sectionnum) {
        $sectionfield = ", cw.section AS sectionnum";
        $sectionjoin  = "LEFT JOIN {course_sections} cw ON cw.id = cm.section";
    }

    $sql = "SELECT cm.*, m.name, md.name AS modname $sectionfield
              FROM {course_modules} cm
                   JOIN {modules} md ON md.id = cm.module
                   JOIN {".$modulename."} m ON m.id = cm.instance
                   $sectionjoin
             WHERE cm.id = :cmid AND md.name = :modulename
                   $courseselect";

    return $DB->get_record_sql($sql, $params, $strictness);
}

/**
 * Given an instance number of a module, finds the coursemodule description
 *
 * Please note that this function performs DB query. When possible use cached course
 * modinfo. For example get_fast_modinfo($courseorid)->instances[$modulename][$instance]
 * See also {@link cm_info::get_course_module_record()}
 *
 * @global object
 * @param string $modulename name of module type, eg. resource, assignment,...
 * @param int $instance module instance number (id in resource, assignment etc. table)
 * @param int $courseid optional course id for extra validation
 * @param bool $sectionnum include relative section number (0,1,2 ...)
 * @param int $strictness IGNORE_MISSING means compatible mode, false returned if record not found, debug message if more found;
 *                        IGNORE_MULTIPLE means return first, ignore multiple records found(not recommended);
 *                        MUST_EXIST means throw exception if no record or multiple records found
 * @return stdClass
 */
function get_coursemodule_from_instance($modulename, $instance, $courseid=0, $sectionnum=false, $strictness=IGNORE_MISSING) {
    global $DB;

    if (!core_component::is_valid_plugin_name('mod', $modulename)) {
        throw new coding_exception('Invalid modulename parameter');
    }

    $params = array('instance'=>$instance, 'modulename'=>$modulename);

    $courseselect = "";
    $sectionfield = "";
    $sectionjoin  = "";

    if ($courseid) {
        $courseselect = "AND cm.course = :courseid";
        $params['courseid'] = $courseid;
    }

    if ($sectionnum) {
        $sectionfield = ", cw.section AS sectionnum";
        $sectionjoin  = "LEFT JOIN {course_sections} cw ON cw.id = cm.section";
    }

    $sql = "SELECT cm.*, m.name, md.name AS modname $sectionfield
              FROM {course_modules} cm
                   JOIN {modules} md ON md.id = cm.module
                   JOIN {".$modulename."} m ON m.id = cm.instance
                   $sectionjoin
             WHERE m.id = :instance AND md.name = :modulename
                   $courseselect";

    return $DB->get_record_sql($sql, $params, $strictness);
}

/**
 * Returns all course modules of given activity in course
 *
 * @param string $modulename The module name (forum, quiz, etc.)
 * @param int $courseid The course id to get modules for
 * @param string $extrafields extra fields starting with m.
 * @return array Array of results
 */
function get_coursemodules_in_course($modulename, $courseid, $extrafields='') {
    global $DB;

    if (!core_component::is_valid_plugin_name('mod', $modulename)) {
        throw new coding_exception('Invalid modulename parameter');
    }

    if (!empty($extrafields)) {
        $extrafields = ", $extrafields";
    }
    $params = array();
    $params['courseid'] = $courseid;
    $params['modulename'] = $modulename;


    return $DB->get_records_sql("SELECT cm.*, m.name, md.name as modname $extrafields
                                   FROM {course_modules} cm, {modules} md, {".$modulename."} m
                                  WHERE cm.course = :courseid AND
                                        cm.instance = m.id AND
                                        md.name = :modulename AND
                                        md.id = cm.module", $params);
}

/**
 * Returns an array of all the active instances of a particular module in given courses, sorted in the order they are defined
 *
 * Returns an array of all the active instances of a particular
 * module in given courses, sorted in the order they are defined
 * in the course. Returns an empty array on any errors.
 *
 * The returned objects includle the columns cw.section, cm.visible,
 * cm.groupmode, cm.groupingid and cm.lang and are indexed by cm.id.
 *
 * @global object
 * @global object
 * @param string $modulename The name of the module to get instances for
 * @param array $courses an array of course objects.
 * @param int $userid
 * @param int $includeinvisible
 * @return array of module instance objects, including some extra fields from the course_modules
 *          and course_sections tables, or an empty array if an error occurred.
 */
function get_all_instances_in_courses($modulename, $courses, $userid=NULL, $includeinvisible=false) {
    global $CFG, $DB;

    if (!core_component::is_valid_plugin_name('mod', $modulename)) {
        throw new coding_exception('Invalid modulename parameter');
    }

    $outputarray = array();

    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return $outputarray;
    }

    list($coursessql, $params) = $DB->get_in_or_equal(array_keys($courses), SQL_PARAMS_NAMED, 'c0');
    $params['modulename'] = $modulename;

    if (!$rawmods = $DB->get_records_sql("SELECT cm.id AS coursemodule, m.*, cw.section, cm.visible AS visible,
                                                 cm.groupmode, cm.groupingid, cm.lang, cm.enableaitools, cm.enabledaiactions
                                            FROM {course_modules} cm, {course_sections} cw, {modules} md,
                                                 {".$modulename."} m
                                           WHERE cm.course $coursessql AND
                                                 cm.instance = m.id AND
                                                 cm.section = cw.id AND
                                                 md.name = :modulename AND
                                                 md.id = cm.module", $params)) {
        return $outputarray;
    }

    foreach ($courses as $course) {
        $modinfo = get_fast_modinfo($course, $userid);

        if (empty($modinfo->instances[$modulename])) {
            continue;
        }

        foreach ($modinfo->instances[$modulename] as $cm) {
            if (!$includeinvisible and !$cm->uservisible) {
                continue;
            }
            if (!isset($rawmods[$cm->id])) {
                continue;
            }
            $outputarray[] = $rawmods[$cm->id];
        }
    }

    return $outputarray;
}

/**
 * Returns an array of all the active instances of a particular module in a given course,
 * sorted in the order they are defined.
 *
 * Returns an array of all the active instances of a particular
 * module in a given course, sorted in the order they are defined
 * in the course. Returns an empty array on any errors.
 *
 * The returned objects includle the columns cw.section, cm.visible,
 * cm.groupmode, and cm.groupingid, and are indexed by cm.id.
 *
 * Simply calls {@link all_instances_in_courses()} with a single provided course
 *
 * @param string $modulename The name of the module to get instances for
 * @param object $course The course obect.
 * @return array of module instance objects, including some extra fields from the course_modules
 *          and course_sections tables, or an empty array if an error occurred.
 * @param int $userid
 * @param int $includeinvisible
 */
function get_all_instances_in_course($modulename, $course, $userid=NULL, $includeinvisible=false) {
    return get_all_instances_in_courses($modulename, array($course->id => $course), $userid, $includeinvisible);
}


/**
 * Determine whether a module instance is visible within a course
 *
 * Given a valid module object with info about the id and course,
 * and the module's type (eg "forum") returns whether the object
 * is visible or not according to the 'eye' icon only.
 *
 * NOTE: This does NOT take into account visibility to a particular user.
 * To get visibility access for a specific user, use get_fast_modinfo, get a
 * cm_info object from this, and check the ->uservisible property; or use
 * the \core_availability\info_module::is_user_visible() static function.
 *
 * @global object

 * @param $moduletype Name of the module eg 'forum'
 * @param $module Object which is the instance of the module
 * @return bool Success
 */
function instance_is_visible($moduletype, $module) {
    global $DB;

    if (!empty($module->id)) {
        $params = array('courseid'=>$module->course, 'moduletype'=>$moduletype, 'moduleid'=>$module->id);
        if ($records = $DB->get_records_sql("SELECT cm.instance, cm.visible, cm.groupingid, cm.id, cm.course
                                               FROM {course_modules} cm, {modules} m
                                              WHERE cm.course = :courseid AND
                                                    cm.module = m.id AND
                                                    m.name = :moduletype AND
                                                    cm.instance = :moduleid", $params)) {

            foreach ($records as $record) { // there should only be one - use the first one
                return $record->visible;
            }
        }
    }
    return true;  // visible by default!
}


/// LOG FUNCTIONS /////////////////////////////////////////////////////

/**
 * Get instance of log manager.
 *
 * @param bool $forcereload
 * @return \core\log\manager
 */
function get_log_manager($forcereload = false) {
    /** @var \core\log\manager $singleton */
    static $singleton = null;

    if ($forcereload and isset($singleton)) {
        $singleton->dispose();
        $singleton = null;
    }

    if (isset($singleton)) {
        return $singleton;
    }

    $classname = '\tool_log\log\manager';
    if (defined('LOG_MANAGER_CLASS')) {
        $classname = LOG_MANAGER_CLASS;
    }

    if (!class_exists($classname)) {
        if (!empty($classname)) {
            debugging("Cannot find log manager class '$classname'.", DEBUG_DEVELOPER);
        }
        $classname = '\core\log\dummy_manager';
    }

    $singleton = new $classname();
    return $singleton;
}

/**
 * Add an entry to the config log table.
 *
 * These are "action" focussed rather than web server hits,
 * and provide a way to easily reconstruct changes to Moodle configuration.
 *
 * @package core
 * @category log
 * @global moodle_database $DB
 * @global stdClass $USER
 * @param    string  $name     The name of the configuration change action
                               For example 'filter_active' when activating or deactivating a filter
 * @param    string  $oldvalue The config setting's previous value
 * @param    string  $value    The config setting's new value
 * @param    string  $plugin   Plugin name, for example a filter name when changing filter configuration
 * @return void
 */
function add_to_config_log($name, $oldvalue, $value, $plugin) {
    global $USER, $DB;

    $log = new stdClass();
    // Use 0 as user id during install.
    $log->userid       = during_initial_install() ? 0 : $USER->id;
    $log->timemodified = time();
    $log->name         = $name;
    $log->oldvalue  = $oldvalue;
    $log->value     = $value;
    $log->plugin    = $plugin;

    $id = $DB->insert_record('config_log', $log);

    $event = core\event\config_log_created::create(array(
            'objectid' => $id,
            'userid' => $log->userid,
            'context' => \context_system::instance(),
            'other' => array(
                'name' => $log->name,
                'oldvalue' => $log->oldvalue,
                'value' => $log->value,
                'plugin' => $log->plugin
            )
        ));
    $event->trigger();
}

/**
 * Store user last access times - called when use enters a course or site
 *
 * @package core
 * @category log
 * @global stdClass $USER
 * @global stdClass $CFG
 * @global moodle_database $DB
 * @uses LASTACCESS_UPDATE_SECS
 * @uses SITEID
 * @param int $courseid  empty courseid means site
 * @return void
 */
function user_accesstime_log($courseid=0) {
    global $USER, $CFG, $DB;

    if (!isloggedin() or \core\session\manager::is_loggedinas()) {
        // no access tracking
        return;
    }

    if (isguestuser()) {
        // Do not update guest access times/ips for performance.
        return;
    }

    if (defined('USER_KEY_LOGIN') && USER_KEY_LOGIN === true) {
        // Do not update user login time when using user key login.
        return;
    }

    if (empty($courseid)) {
        $courseid = SITEID;
    }

    $timenow = time();

/// Store site lastaccess time for the current user
    if ($timenow - $USER->lastaccess > LASTACCESS_UPDATE_SECS) {
    /// Update $USER->lastaccess for next checks
        $USER->lastaccess = $timenow;

        $last = new stdClass();
        $last->id         = $USER->id;
        $last->lastip     = getremoteaddr();
        $last->lastaccess = $timenow;

        $DB->update_record_raw('user', $last);
    }

    if ($courseid == SITEID) {
    ///  no user_lastaccess for frontpage
        return;
    }

/// Store course lastaccess times for the current user
    if (empty($USER->currentcourseaccess[$courseid]) or ($timenow - $USER->currentcourseaccess[$courseid] > LASTACCESS_UPDATE_SECS)) {

        $lastaccess = $DB->get_field('user_lastaccess', 'timeaccess', array('userid'=>$USER->id, 'courseid'=>$courseid));

        if ($lastaccess === false) {
            // Update course lastaccess for next checks
            $USER->currentcourseaccess[$courseid] = $timenow;

            $last = new stdClass();
            $last->userid     = $USER->id;
            $last->courseid   = $courseid;
            $last->timeaccess = $timenow;
            try {
                $DB->insert_record_raw('user_lastaccess', $last, false);
            } catch (dml_write_exception $e) {
                // During a race condition we can fail to find the data, then it appears.
                // If we still can't find it, rethrow the exception.
                $lastaccess = $DB->get_field('user_lastaccess', 'timeaccess', array('userid' => $USER->id,
                                                                                    'courseid' => $courseid));
                if ($lastaccess === false) {
                    throw $e;
                }
                // If we did find it, the race condition was true and another thread has inserted the time for us.
                // We can just continue without having to do anything.
            }

        } else if ($timenow - $lastaccess <  LASTACCESS_UPDATE_SECS) {
            // no need to update now, it was updated recently in concurrent login ;-)

        } else {
            // Update course lastaccess for next checks
            $USER->currentcourseaccess[$courseid] = $timenow;

            $DB->set_field('user_lastaccess', 'timeaccess', $timenow, array('userid'=>$USER->id, 'courseid'=>$courseid));
        }
    }
}

/// GENERAL HELPFUL THINGS  ///////////////////////////////////

/**
 * Dumps a given object's information for debugging purposes. (You can actually use this function
 * to print any type of value such as arrays or simple strings, not just objects.)
 *
 * When used in a web script, the object is dumped in a fancy-formatted div.
 *
 * When used in a CLI script, the object's information is written to the standard error output
 * stream.
 *
 * When used in an AJAX script, the object's information is dumped to the server error log.
 *
 * In text mode, private fields are shown with * and protected with +.
 *
 * In web view, formatting is done with Bootstrap classes. You can hover over some items to see
 * more information, such as value types or access controls, or full field names if the names get
 * cut off.
 *
 * By default, this will recurse to child objects, except where that would result in infinite
 * recursion. To change that, set $expandclasses to an empty array (= do not recurse) or to a list
 * of the class names that you would like to expand. You can also set values in this array to a
 * regular expression beginning with / if you want to match a range of classes.
 *
 * @param mixed $item Object, array, or other item to display
 * @param string[] $expandclasses Optional list of class patterns to recurse to
 * @param bool $textonly If true, outputs text-only (automatically set for CLI and AJAX)
 * @param bool $return For internal use - if true, returns value instead of echoing it
 * @param int $depth For internal use - depth of recursion within print_object call
 * @param \stdClass[] $done For internal use - array listing already-printed objects
 * @return string  HTML code (or text if CLI) to display, if $return is true, otherwise empty string
 */
function print_object($item, array $expandclasses = ['/./'], bool $textonly = false, bool $return = false,
        int $depth = 0, array $done = []): string {
    // We may need a lot of memory here.
    raise_memory_limit(MEMORY_EXTRA);

    // Set text (instead of HTML) mode if in CLI or AJAX script.
    if (CLI_SCRIPT || AJAX_SCRIPT) {
        $textonly = true;
    }

    /**
     * Gets styling for types of variable.
     *
     * @param mixed $item Arbitrary PHP variable (simple primitive type) to display
     * @return string Bootstrap class for styling the display
     */
    $gettypestyle = function($item): string {
        switch (gettype($item)) {
            case 'NULL':
            case 'boolean':
                return 'fst-italic';
            case 'integer':
            case 'double':
                return 'text-primary';
            case 'string' :
                return 'text-success';
            default:
                return '';
        }
    };

    /**
     * Formats and escapes the text for the contents of a variable.
     *
     * @param mixed $item Arbitrary PHP variable (simple primitive type) to display
     * @return string Contents as text
     */
    $getobjectstr = function($item) use($textonly): string {
        if (is_null($item)) {
            return 'null';
        }
        $objectstr = (string)$item;
        if (is_string($item)) {
            // Quotes around strings.
            $objectstr = "'$objectstr'";
        } else if (is_bool($item)) {
            // Show true or false for bools.
            $objectstr = $item ? 'true' : 'false';
        } else if (is_float($item)) {
            // Add 'f' for floats.
            $objectstr = $item . 'f';
        }
        if ($textonly) {
            return $objectstr;
        } else {
            return s($objectstr);
        }
    };

    if ($textonly) {
        $out = '';
    } else {
        $notype = false;
        $cssclass = $gettypestyle($item);
        if (is_object($item) || is_array($item)) {
            // For object and array, don't show the title on hover - it makes no sense because
            // they're big, plus we already show the word 'array' or the object type.
            $notype = true;
            // Add a fancy box, with alternating colour, around the object and non-empty array.
            if (is_object($item) || count($item) > 0) {
                if (($depth & 1) === 0) {
                    $cssclass .= ' bg-white rounded p-2';
                } else {
                    $cssclass .= ' bg-light rounded p-2';
                }
            }
        }
        if ($depth === 0) {
            // The top-level object being printed has print-object class in case anyone wants to
            // do extra styling.
            $cssclass .= ' print-object';
        }
        $attributes = [];
        if (!$notype) {
            // We show the item type on hover. Note there is no need to include the actual value
            // in the title attribute here, because the full text will be displayed anyway with
            // wrapping if needed..
            $attributes['title'] = gettype($item);
        }
        $out = html_writer::start_div($cssclass, $attributes);
    }

    // Depending on the level of nesting, we allocate a slightly different proportion (ranging
    // from 2/12 to 5/12) of the available width for the key names.
    $bsdepth = floor(min(6, $depth) / 2);
    $bootstrapdt = 'col-sm-' . ($bsdepth + 2);
    $bootstrapdd = 'col-sm-' . (12 - ($bsdepth + 2));

    // This main code handles objects and arrays.
    if (is_array($item) || is_object($item)) {
        if (is_object($item)) {
            // Object header: class name.
            if ($textonly) {
                $out .= '[' . get_class($item) . ']';
            } else {
                // Objects display the class name as a badge. Content goes within a <dl>.
                $badge = html_writer::span(get_class($item), 'badge bg-primary text-white');
                $out .= html_writer::tag('h5', $badge);
                $out .= html_writer::start_tag('dl', ['class' => 'row']);
                $dl = true;
            }
            // Record that we have output this object already (to prevent circular refs).
            $done[] = $item;
            $object = true;
            // Cast to array so we can loop through all properties.
            $item = (array)$item;
        } else {
            // Array header: 'array' and a count.
            $arrayinfo = 'array (' . count($item) . ')';
            if ($textonly) {
                $out .= $arrayinfo;
            } else {
                // Arrays show the same as objects but the badge is grey.
                $badge = html_writer::span($arrayinfo, 'badge bg-secondary text-dark');
                // Decide if there will be a <dl> tag - only if there is some content.
                $dl = count($item) > 0;
                $attributes = [];
                if (!$dl) {
                    // When there is no content inside the array, don't show bottom margin on heading.
                    $attributes['class'] = 'mb-0';
                }
                $out .= html_writer::tag('h5', $badge, $attributes);
                if ($dl) {
                    $out .= html_writer::start_tag('dl', ['class' => 'row']);
                }
            }
            $object = false;
        }

        // Properties.
        foreach ($item as $key => $value) {
            // Detect private and protected variables.
            $matches = [];
            $stringkey = (string)$key;
            if (preg_match('~^\x00(.*)\x00(.*)$~', $stringkey, $matches)) {
                $shortkey = $matches[2];
                $access = $matches[1] == '*' ? 'protected' : 'private';
            } else {
                $shortkey = $stringkey;
                $access = 'public';
            }
            if ($textonly) {
                switch ($access) {
                    case 'protected' :
                        $shortkey = '+' . $shortkey;
                        break;
                    case 'private' :
                        $shortkey = '*' . $shortkey;
                        break;
                }
                $out .= PHP_EOL . '  ' . $shortkey . ' = ';
            } else {
                switch ($access) {
                    case 'protected':
                        // Protected is in normal font.
                        $bootstrapstyle = ' fw-normal';
                        break;
                    case 'private':
                        // Private is italic.
                        $bootstrapstyle = ' fw-normal fst-italic';
                        break;
                    default:
                        // Public is bold, same for array keys.
                        $bootstrapstyle = '';
                        break;
                }
                $attributes = ['class' => $bootstrapdt . ' text-truncate' . $bootstrapstyle];
                if ($object) {
                    // For an object property, the title is the full text of the key (in case it
                    // gets cut off) and the access modifier.
                    $attributes['title'] = s($shortkey) . ' (' . $access . ')';
                    $objectstr = s($shortkey);
                } else {
                    // For an array key, the title is the full text of the key (in case it gets
                    // cut off) and the type of the key. Array keys can't have an access modifier.
                    $attributes['title'] = s($shortkey) . ' (' . gettype($key) . ')';
                    // Array keys are styled according to the normal styling for that type.
                    $typestyle = $gettypestyle($key);
                    if ($typestyle) {
                        $attributes['class'] .= ' ' . $typestyle;
                    }
                    // Array keys also use a special object string e.g. 'true' for bool, quoted.
                    $objectstr = $getobjectstr($key);
                }
                $out .= html_writer::tag('dt', $objectstr, $attributes);
            }
            // Consider how to display the value for this key.
            $extraclass = '';
            switch (gettype($value)) {
                case 'object' :
                    $objclass = get_class($value);

                    // See if we printed it further up the tree in which case
                    // it will definitely not be printed (infinite recursion).
                    if (in_array($value, $done)) {
                        if ($textonly) {
                            $display = '[circular reference: ' . $objclass . ']';
                        } else {
                            $display = '[circular reference: ' . s($objclass) . ']';
                            $extraclass = ' text-danger';
                        }
                        break;
                    }

                    // Recurse only to specified types.
                    $recurse = false;
                    foreach ($expandclasses as $pattern) {
                        if (substr($pattern, 0, 1) === '/') {
                            // Allow regular expressions beginning with a / symbol.
                            if (preg_match($pattern, $objclass)) {
                                $recurse = true;
                                break;
                            }
                        } else {
                            // Other strings must be exact match.
                            if ($objclass === $pattern) {
                                $recurse = true;
                                break;
                            }
                        }
                    }

                    if ($recurse) {
                        // Recursively display the object.
                        $display = print_object($value, $expandclasses, $textonly, true, $depth + 1, $done);
                        if ($textonly) {
                            // Indent by adding spaces after each LF.
                            $display = str_replace(PHP_EOL, PHP_EOL . '  ', $display);
                        }
                    } else {
                        // Do not display the object, just a marker in square breackets.
                        if ($textonly) {
                            $display = '[object: ' . $objclass . ']';
                        } else {
                            $display = '[object: ' . s($objclass) . ']';
                        }
                    }
                    break;

                case 'array' :
                    // Recursively display the array.
                    $display = print_object($value, $expandclasses, $textonly, true, $depth + 1, $done);
                    if ($textonly) {
                        // Indent by adding spaces after each LF.
                        $display = str_replace(PHP_EOL, PHP_EOL . '  ', $display);
                    }
                    break;

                default:
                    // Plain value - recurse to display.
                    $display = print_object($value, [], $textonly, true, $depth + 1);
                    break;
            }
            if ($textonly) {
                $out .= $display;
            } else {
                $out .= html_writer::tag('dd', $display, ['class' => $bootstrapdd . $extraclass]);
            }
        }
        if (!$textonly && $dl) {
            $out .= html_writer::end_tag('dl');
        }
    } else {
        // For things which are not objects or arrays, just convert to string for display.
        $out .= $getobjectstr($item);
    }

    if (!$textonly) {
        $out .= html_writer::end_div();
    }

    // Display or return result.
    if ($return) {
        return $out;
    } else {
        if (CLI_SCRIPT) {
            fwrite(STDERR, $out);
            fwrite(STDERR, PHP_EOL);
        } else if (AJAX_SCRIPT) {
            foreach (explode(PHP_EOL, $out) as $line) {
                error_log($line);
            }
        } else {
            if ($textonly) {
                $out = html_writer::tag('pre', s($out));
            }
            echo $out . "\n";
        }
        return '';
    }
}

/**
 * This function is the official hook inside XMLDB stuff to delegate its debug to one
 * external function.
 *
 * Any script can avoid calls to this function by defining XMLDB_SKIP_DEBUG_HOOK before
 * using XMLDB classes. Obviously, also, if this function doesn't exist, it isn't invoked ;-)
 *
 * @uses DEBUG_DEVELOPER
 * @param string $message string contains the error message
 * @param object $object object XMLDB object that fired the debug
 */
function xmldb_debug($message, $object) {

    debugging($message, DEBUG_DEVELOPER);
}

/**
 * @global object
 * @uses CONTEXT_COURSECAT
 * @return boolean Whether the user can create courses in any category in the system.
 */
function user_can_create_courses() {
    global $DB;
    $catsrs = $DB->get_recordset('course_categories');
    foreach ($catsrs as $cat) {
        if (has_capability('moodle/course:create', context_coursecat::instance($cat->id))) {
            $catsrs->close();
            return true;
        }
    }
    $catsrs->close();
    return false;
}

/**
 * This method can update the values in mulitple database rows for a colum with
 * a unique index, without violating that constraint.
 *
 * Suppose we have a table with a unique index on (otherid, sortorder), and
 * for a particular value of otherid, we want to change all the sort orders.
 * You have to do this carefully or you will violate the unique index at some time.
 * This method takes care of the details for you.
 *
 * Note that, it is the responsibility of the caller to make sure that the
 * requested rename is legal. For example, if you ask for [1 => 2, 2 => 2]
 * then you will get a unique key violation error from the database.
 *
 * @param string $table The database table to modify.
 * @param string $field the field that contains the values we are going to change.
 * @param array $newvalues oldvalue => newvalue how to change the values.
 *      E.g. [1 => 4, 2 => 1, 3 => 3, 4 => 2].
 * @param array $otherconditions array fieldname => requestedvalue extra WHERE clause
 *      conditions to restrict which rows are affected. E.g. array('otherid' => 123).
 * @param int $unusedvalue (defaults to -1) a value that is never used in $ordercol.
 */
function update_field_with_unique_index($table, $field, array $newvalues,
        array $otherconditions, $unusedvalue = -1) {
    global $DB;
    $safechanges = decompose_update_into_safe_changes($newvalues, $unusedvalue);

    $transaction = $DB->start_delegated_transaction();
    foreach ($safechanges as $change) {
        list($from, $to) = $change;
        $otherconditions[$field] = $from;
        $DB->set_field($table, $field, $to, $otherconditions);
    }
    $transaction->allow_commit();
}

/**
 * Helper used by {@link update_field_with_unique_index()}. Given a desired
 * set of changes, break them down into single udpates that can be done one at
 * a time without breaking any unique index constraints.
 *
 * Suppose the input is array(1 => 2, 2 => 1) and -1. Then the output will be
 * array (array(1, -1), array(2, 1), array(-1, 2)). This function solves this
 * problem in the general case, not just for simple swaps. The unit tests give
 * more examples.
 *
 * Note that, it is the responsibility of the caller to make sure that the
 * requested rename is legal. For example, if you ask for something impossible
 * like array(1 => 2, 2 => 2) then the results are undefined. (You will probably
 * get a unique key violation error from the database later.)
 *
 * @param array $newvalues The desired re-ordering.
 *      E.g. array(1 => 4, 2 => 1, 3 => 3, 4 => 2).
 * @param int $unusedvalue A value that is not currently used.
 * @return array A safe way to perform the re-order. An array of two-element
 *      arrays array($from, $to).
 *      E.g. array(array(1, -1), array(2, 1), array(4, 2), array(-1, 4)).
 */
function decompose_update_into_safe_changes(array $newvalues, $unusedvalue) {
    $nontrivialmap = array();
    foreach ($newvalues as $from => $to) {
        if ($from == $unusedvalue || $to == $unusedvalue) {
            throw new \coding_exception('Supposedly unused value ' . $unusedvalue . ' is actually used!');
        }
        if ($from != $to) {
            $nontrivialmap[$from] = $to;
        }
    }

    if (empty($nontrivialmap)) {
        return array();
    }

    // First we deal with all renames that are not part of cycles.
    // This bit is O(n^2) and it ought to be possible to do better,
    // but it does not seem worth the effort.
    $safechanges = array();
    $nontrivialmapchanged = true;
    while ($nontrivialmapchanged) {
        $nontrivialmapchanged = false;

        foreach ($nontrivialmap as $from => $to) {
            if (array_key_exists($to, $nontrivialmap)) {
                continue; // Cannot currenly do this rename.
            }
            // Is safe to do this rename now.
            $safechanges[] = array($from, $to);
            unset($nontrivialmap[$from]);
            $nontrivialmapchanged = true;
        }
    }

    // Are we done?
    if (empty($nontrivialmap)) {
        return $safechanges;
    }

    // Now what is left in $nontrivialmap must be a permutation,
    // which must be a combination of disjoint cycles. We need to break them.
    while (!empty($nontrivialmap)) {
        // Extract the first cycle.
        reset($nontrivialmap);
        $current = $cyclestart = key($nontrivialmap);
        $cycle = array();
        do {
            $cycle[] = $current;
            $next = $nontrivialmap[$current];
            unset($nontrivialmap[$current]);
            $current = $next;
        } while ($current != $cyclestart);

        // Now convert it to a sequence of safe renames by using a temp.
        $safechanges[] = array($cyclestart, $unusedvalue);
        $cycle[0] = $unusedvalue;
        $to = $cyclestart;
        while ($from = array_pop($cycle)) {
            $safechanges[] = array($from, $to);
            $to = $from;
        }
    }

    return $safechanges;
}

/**
 * Return maximum number of courses in a category
 *
 * @return int number of courses
 */
function get_max_courses_in_category() {
    global $CFG;
    // The default when $CFG->maxcoursesincategory is not set or invalid.
    if (empty($CFG->maxcoursesincategory) || (int) $CFG->maxcoursesincategory <= 0) {
        $CFG->maxcoursesincategory = 10000;
    }
    return $CFG->maxcoursesincategory;
}

/**
 * Prepare a safe ORDER BY statement from user interactable requests.
 *
 * This allows safe user specified sorting (ORDER BY), by abstracting the SQL from the value being requested by the user.
 * A standard string (and optional direction) can be specified, which will be mapped to a predefined allow list of SQL ordering.
 * The mapping can optionally include a 'default', which will be used if the key provided is invalid.
 *
 * Example usage:
 *      -If $orderbymap = [
 *              'courseid' => 'c.id',
 *              'somecustomvalue'=> 'c.startdate, c.shortname',
 *              'default' => 'c.fullname',
 *       ]
 *      -A value from the map array's keys can be passed in by a user interaction (eg web service) along with an optional direction.
 *      -get_safe_orderby($orderbymap, 'courseid', 'DESC') would return: ORDER BY c.id DESC
 *      -get_safe_orderby($orderbymap, 'somecustomvalue') would return: ORDER BY c.startdate, c.shortname
 *      -get_safe_orderby($orderbymap, 'invalidblah', 'DESC') would return: ORDER BY c.fullname DESC
 *      -If no default key was specified in $orderbymap, the invalidblah example above would return empty string.
 *
 * @param array $orderbymap An array in the format [keystring => sqlstring]. A default fallback can be set with the key 'default'.
 * @param string $orderbykey A string to be mapped to a key in $orderbymap.
 * @param string $direction Optional ORDER BY direction (ASC/DESC, case insensitive).
 * @param bool $useprefix Whether ORDER BY is prefixed to the output (true by default). This should not be modified in most cases.
 *                        It is included to enable get_safe_orderby_multiple() to use this function multiple times.
 * @return string The ORDER BY statement, or empty string if $orderbykey is invalid and no default is mapped.
 */
function get_safe_orderby(array $orderbymap, string $orderbykey, string $direction = '', bool $useprefix = true): string {
    $orderby = $useprefix ? ' ORDER BY ' : '';
    $output = '';

    // Only include an order direction if ASC/DESC is explicitly specified (case insensitive).
    $direction = strtoupper($direction);
    if (!in_array($direction, ['ASC', 'DESC'], true)) {
        $direction = '';
    } else {
        $direction = " {$direction}";
    }

    // Prepare the statement if the key maps to a defined sort parameter.
    if (isset($orderbymap[$orderbykey])) {
        $output = "{$orderby}{$orderbymap[$orderbykey]}{$direction}";
    } else if (array_key_exists('default', $orderbymap)) {
        // Fall back to use the default if one is specified.
        $output = "{$orderby}{$orderbymap['default']}{$direction}";
    }

    return $output;
}

/**
 * Prepare a safe ORDER BY statement from user interactable requests using multiple values.
 *
 * This allows safe user specified sorting (ORDER BY) similar to get_safe_orderby(), but supports multiple keys and directions.
 * This is useful in cases where combinations of columns are needed and/or each item requires a specified direction (ASC/DESC).
 * The mapping can optionally include a 'default', which will be used if the key provided is invalid.
 *
 * Example usage:
 *      -If $orderbymap = [
 *              'courseid' => 'c.id',
 *              'fullname'=> 'c.fullname',
 *              'default' => 'c.startdate',
 *          ]
 *      -An array of values from the map's keys can be passed in by a user interaction (eg web service), with optional directions.
 *      -get_safe_orderby($orderbymap, ['courseid', 'fullname'], ['DESC', 'ASC']) would return: ORDER BY c.id DESC, c.fullname ASC
 *      -get_safe_orderby($orderbymap, ['courseid', 'invalidblah'], ['aaa', 'DESC']) would return: ORDER BY c.id, c.startdate DESC
 *      -If no default key was specified in $orderbymap, the invalidblah example above would return: ORDER BY c.id
 *
 * @param array $orderbymap An array in the format [keystring => sqlstring]. A default fallback can be set with the key 'default'.
 * @param array $orderbykeys An array of strings to be mapped to keys in $orderbymap.
 * @param array $directions Optional array of ORDER BY direction (ASC/DESC, case insensitive).
 *                          The array keys should match array keys in $orderbykeys.
 * @return string The ORDER BY statement, or empty string if $orderbykeys contains no valid items and no default is mapped.
 */
function get_safe_orderby_multiple(array $orderbymap, array $orderbykeys, array $directions = []): string {
    $output = '';

    // Check each key for a valid mapping and add to the ORDER BY statement (invalid entries will be empty strings).
    foreach ($orderbykeys as $index => $orderbykey) {
        $direction = $directions[$index] ?? '';
        $safeorderby = get_safe_orderby($orderbymap, $orderbykey, $direction, false);

        if (!empty($safeorderby)) {
            $output .= ", {$safeorderby}";
        }
    }

    // Prefix with ORDER BY if any valid ordering is specified (and remove comma from the start).
    if (!empty($output)) {
        $output = ' ORDER BY' . ltrim($output, ',');
    }

    return $output;
}
