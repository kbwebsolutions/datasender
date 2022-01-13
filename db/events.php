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
 * Event handler definition for local_datasender
 * .
 *
 * @package local_tbc
 * @author Marcus Green
 * @copyright 2021 Titus Learning
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// List of observers.
$observers = [
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback' => 'local_datasender\observer::quiz_attempt_submitted'
    ],
    [
        'eventname' => '\core\event\role_assigned',
        'callback' => 'local_datasender\observer::user_role_assigned'
    ],
    [
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback' => 'local_datasender\observer::assessable_submitted'
    ],
    [
        'eventname' => '\mod_assign\event\marker_updated',
        'callback' => 'local_datasender\observer::marker_updated'
    ],
    [
        'eventname' => '\mod_assign\event\workflow_state_updated',
        'callback' => 'local_datasender\observer::workflow_state_updated'
    ],
    [
        'eventname' => '\mod_assign\event\submission_graded',
        'callback' => 'local_datasender\observer::submission_graded'
    ]

];
