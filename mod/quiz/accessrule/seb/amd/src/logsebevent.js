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
 * Log action when user quit seb
 *
 * @module     quizaccess_seb/logsebevent
 * @author     Van Binh DANG <van.binh.dang@invite.umontreal.ca>
 * @copyright  2023 Université de Montréal
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

/**
 * Validate keys in Moodle backend.
 * @param {init} cmid Value of course module id of the quiz.
 * @param {init} userid Value of current user id.
 */
export const quitsebevent = (cmid, userid) => {
    const request = {
        methodname: 'quizaccess_seb_quit_seb',
        args: {
            cmid: cmid,
            userid: userid
        },
    };

    let res = Ajax.call([request]);
    res[0].fail(Notification.exception);
    return res[0];
};

export const init = (cmid, userid) => {
    let ob = document.getElementsByClassName('exitsebbutton')[0];
    if (ob) {
        ob.addEventListener('click', function() {
            quitsebevent(cmid, userid);
        });
    }
};
