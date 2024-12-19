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

namespace quizaccess_seb\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;

use external_api;
use external_function_parameters;
use external_value;

require_once($CFG->libdir . '/externallib.php');

/**
 * Log action when user quit seb
 *
 * @package    quizaccess_seb
 * @author     Van Binh DANG <van.binh.dang@invite.umontreal.ca>
 * @copyright  2023 Université de Montréal
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quit_seb extends external_api {

    /**
     * External function parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
           'cmid' => new external_value(PARAM_INT, 'Course module ID',
                VALUE_REQUIRED, null, NULL_NOT_ALLOWED),
           'userid' => new external_value(PARAM_INT, 'Current user ID',
                VALUE_REQUIRED, null, NULL_NOT_ALLOWED),
        ]);
    }

    /**
     * Validate a SEB config key or browser exam key.
     *
     * @param string $cmid Course module ID.
     * @param string $userid Current user ID.
     * @return bool
     */
    public static function execute(string $cmid, string $userid): bool {
        $coursemodule = get_coursemodule_from_id('quiz', $cmid);
        $quizid = $coursemodule->instance;
        $params = [
            'objectid' => $cmid,
            'relateduserid' => $userid,
            'courseid' => $coursemodule->course,
            'context' => \context_module::instance($cmid),
            'other' => [
                'quizid' => $quizid,
                'page' => 0,
            ],
        ];
        $event = \mod_quiz\event\seb_quit::create($params);
        $event->add_record_snapshot('quiz_attempts', new \stdClass());
        $event->trigger();

        return true;
    }

    /**
     * External function returns.
     *
     * @return \external_description
     */
    public static function execute_returns() {
        return new external_value(PARAM_RAW, 'The description scale');
    }

    /**
     * Check if there is a valid quiz corresponding to a course module it.
     *
     * @param string $cmid Course module ID.
     * @return int Returns quiz id if cmid matches valid quiz, or 0 if there is no match.
     */
    private static function get_quiz_id(string $cmid): int {
        $quizid = 0;

        $coursemodule = get_coursemodule_from_id('quiz', $cmid);
        if (!empty($coursemodule)) {
            $quizid = $coursemodule->instance;
        }

        return $quizid;
    }
}

