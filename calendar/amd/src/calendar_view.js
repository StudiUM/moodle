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
 * This module is responsible for handling calendar day and upcoming view.
 *
 * @module     core_calendar/calendar
 * @copyright  2017 Simey Lameze <simey@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
        'jquery',
        'core/str',
        'core/notification',
        'core_calendar/selectors',
        'core_calendar/events',
        'core_calendar/view_manager',
        'core_calendar/repository',
        'core/modal_factory',
        'core_calendar/modal_event_form',
        'core/modal_events',
        'core_calendar/crud'
    ],
    function(
        $,
        Str,
        Notification,
        CalendarSelectors,
        CalendarEvents,
        CalendarViewManager,
        CalendarRepository,
        ModalFactory,
        ModalEventForm,
        ModalEvents,
        CalendarCrud
    ) {

        var registerEventListeners = function(root, type) {
            var body = $('body');

            CalendarCrud.registerRemove(root);

            var reloadFunction = 'reloadCurrent' + type.charAt(0).toUpperCase() + type.slice(1);

            body.on(CalendarEvents.created, function() {
                CalendarViewManager[reloadFunction](root);
                root.find(CalendarSelectors.groupSelector).val(0);
            });
            body.on(CalendarEvents.deleted, function() {
                CalendarViewManager[reloadFunction](root);
                root.find(CalendarSelectors.groupSelector).val(0);
            });
            body.on(CalendarEvents.updated, function() {
                CalendarViewManager[reloadFunction](root);
                root.find(CalendarSelectors.groupSelector).val(0);
            });

            root.on('change', CalendarSelectors.courseSelector, function() {
                var selectElement = $(this);
                var courseId = selectElement.val();
                var courseSelector = root.find(CalendarSelectors.elements.courseSelector);

                var groupSelector = root.find(CalendarSelectors.elements.groupSelector);
                var firstOption = groupSelector.children().first();
                groupSelector.empty();
                groupSelector.append('<option value="' + firstOption.val() + '">' + firstOption.text() + '</option>');

                if (courseId > 1) {
                    CalendarViewManager.reloadGroupSelector(courseId)
                        .done(function(data) {
                            if (data.length === 0) {
                                $('#coursegroupslabel').hide();
                                groupSelector.hide();
                                courseSelector.addClass('mr-auto');
                            } else {
                                $('#coursegroupslabel').show();
                                groupSelector.show();
                                courseSelector.removeClass('mr-auto');

                                $.each(data, function(index, value) {
                                    $("<option/>", {
                                        value: value.id,
                                        text: value.name.length > 15 ? value.name.substring(0, 14) + '...' : value.name
                                    }).appendTo(groupSelector);
                                });
                            }
                        });
                } else {
                    groupSelector.hide();
                    courseSelector.addClass('mr-auto');
                }

                CalendarViewManager[reloadFunction](root, courseId, null)
                    .then(function() {
                        // We need to get the selector again because the content has changed.
                        return root.find(CalendarSelectors.courseSelector).val(courseId);
                    })
                    .then(function() {
                        CalendarViewManager.updateUrl('?view=upcoming&course=' + courseId);
                    })
                    .fail(Notification.exception);
            });

            root.on('change', CalendarSelectors.elements.groupSelector, function() {
                var groupId = parseInt($(this).val());
                var groupEvents = root.find(CalendarSelectors.eventType.group);
                var nogroupsElement = $('#nogroups');
                var eventCount = 0;

                if (nogroupsElement.length > 0) {
                    nogroupsElement.remove();
                }

                if (groupEvents.length > 0) {
                    $.each(groupEvents, function(index, value) {
                        let eventElement = $(value);

                        if (groupId === 0 || eventElement.data('event-groupid') === groupId) {
                            eventElement.show();
                            eventCount++;
                        } else {
                            eventElement.hide();
                        }
                    });

                    if (eventCount === 0) {
                        var eventlistElement = $(".eventlist");
                        var nogroupeventsElement = $('<span id="nogroups" class="calendar-information calendar-no-results"></span>')
                            .appendTo(eventlistElement);

                        Str.get_string('nogroupevents', 'core_calendar')
                            .done(function(data) {
                                nogroupeventsElement.text(data);
                            });
                    }
                }
            });

            body.on(CalendarEvents.filterChanged, function(e, data) {
                var daysWithEvent = root.find(CalendarSelectors.eventType[data.type]);
                if (data.hidden == true) {
                    daysWithEvent.addClass('hidden');
                } else {
                    daysWithEvent.removeClass('hidden');
                }
                CalendarViewManager.foldDayEvents(root);
            });

            var eventFormPromise = CalendarCrud.registerEventFormModal(root);
            CalendarCrud.registerEditListeners(root, eventFormPromise);
        };

        return {
            init: function(root, type) {
                root = $(root);
                CalendarViewManager.init(root, type);
                registerEventListeners(root, type);
            }
        };
    });
