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
namespace local_datasender;

use mod_assign\event\marker_updated;

use local_tlconnect\api;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/locallib.php');

class observer {

    /**
     * Process the quiz_submitted event. The plan is to write the data to a queue table
     * which will allow multiple connectsions to the remote endpoint, and also make it
     * easier to write unit tests.
     *
     * @param quiz_attempt_submitted $event
     * @return mixed|void
     * @throws \coding_exception
     */
    public static function quiz_attempt_submitted(\mod_quiz\event\attempt_submitted $event) {
        global $DB, $CFG;
        $queuedata  = [];
        $eventdata = $event->get_data();
        $course = $DB->get_record('course', ['id' => $eventdata['courseid']]);
        if ($course->idnumber == "") {
            return false;
        }

        $user = $DB->get_record('user', ['id' => $eventdata['relateduserid']]);
        if (!self::has_role($user, 'student', $course)) {
            return false;
        }

        $modinfo = get_fast_modinfo($eventdata['courseid']);
        $quiz  = $modinfo->get_cm($eventdata['contextinstanceid']);

        $gradesinfo = (object) grade_get_grades($course->id, 'mod', 'quiz', $quiz->instance, $user->id);

        $item = $gradesinfo->items[0];
        $grade = $item->grades[$user->id];
        $gradetosend = ($grade->grade >= $item->gradepass) ? "pass" : "fail";

        $queuedata['Course__c'] = $course->idnumber;
        $queuedata['Candidate__r']['APIID__c'] = $user->username;
        $queuedata['Title__c'] = $quiz->name;
        $queuedata['Completion_Date__c'] = date("Y-m-d\TH:i:s", $eventdata['timecreated']);
        $queuedata['Quiz_URL__c'] = $CFG->wwwroot.'/mod/quiz/view.php?id='.$eventdata['contextinstanceid'];
        $queuedata['Quiz_Id__c']  = $quiz->idnumber;
        $queuedata['Grade__c'] = $gradetosend;

        $endpoint = get_config('local_tlconnect','endpointurl').'/services/data/v53.0/sobjects/Quiz__c';
        $logdata = 'attempt_submitted event by user: '.$user->username .' for Quiz: '
            .$quiz->name .' on course:'.$course->shortname. ' gradesent:'.$gradetosend .' quiz idnumber:'.$quiz->idnumber;

        self::write_queuedata($queuedata, 'quiz attempt_submitted', $logdata, $endpoint);
    }

    /**
     * If the user has more than one role return false.
     * If the role they have does not match the rolename
     * return false. Discussions for and against this approach
     * here https://moodle.org/mod/forum/discuss.php?d=404474
     *
     * @param \stdClass $user
     * @param string $rolename
     * @param\stdClass $course
     * @return boolean
     */
    public static function has_role($user, $rolename, $course) :bool {
        $context = \context_course::instance($course->id);
        $roles = get_user_roles($context, $user->id, true);
        $role = key($roles);

        if (count($roles) > 1 || $roles[$role]->shortname !== $rolename) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Process the user enrolment event. At the moment it doesn't return
     * anything, but in the future it will eather call the adapter or
     * write to a table that will act as a queue.
     *
     * @param \mod_assign\event\assessable_submitted $event
     * @return void
     */
    public static function user_role_assigned(\core\event\role_assigned $event) {
        global $DB;
        $queuedata = [];
        $eventdata = $event->get_data();
        $course = $DB->get_record('course', ['id' => $eventdata['courseid']]);
        if ($course->idnumber == "") {
            return;
        }

        $snapshotid = $eventdata['other']['id'];
        $snapshot = $event->get_record_snapshot('role_assignments', $snapshotid);

        $roleid = $snapshot->roleid;
        $rolename = $DB->get_records_sql("SELECT shortname from {role} WHERE id = ?", array($roleid));
        $rolename = array_pop($rolename);
        $rolename = $rolename->shortname;
        if ($rolename !== 'student') {
            return false;
        }
        $user = $DB->get_record('user', ['id' => $eventdata['relateduserid']]);

        $queuedata['Candidate__r']['APIID__c'] = $user->username;
        $queuedata['Course__c'] = $course->idnumber;
        $queuedata['Date_Of_Enrolment__c'] = date("Y-m-d\TH:i:s", $eventdata['timecreated']);

        $endpoint = get_config('local_tlconnect','endpointurl').'/services/data/v53.0/sobjects/Enrolment__c';
        $logdata = 'user_enrolment_created event for user:'.$user->username .' on course:'.$course->shortname;

        self::write_queuedata($queuedata, 'user_enrolment_created', $logdata, $endpoint);
    }

    /**
     * process the assign submitted event.
     *
     * @param \mod_assign\event\assessable_submitted $event
     * @return void
     */
    public static function assessable_submitted(\mod_assign\event\assessable_submitted $event) {
        global $DB, $CFG;
        $queuedata  = [];
        $eventdata = $event->get_data();
        $course = $DB->get_record('course', ['id' => $eventdata['courseid']]);
        $user = $DB->get_record('user', ['id' => $eventdata['userid']]);

        if ($course->idnumber == "") {
            return;
        }
        if (!self::has_role($user, 'student', $course)) {
            return false;
        }
        $modinfo = get_fast_modinfo($eventdata['courseid']);
        $assign  = $modinfo->get_cm($eventdata['contextinstanceid']);

        $queuedata['Candidate__r']['APIID__c'] = $user->username;
        $queuedata['Course__c'] = $course->idnumber;
        $queuedata['Assessment_ID__c'] = $user->username .'_'. $assign->idnumber;
        $queuedata['Title__c']  = $assign->name;
        $queuedata['Assessment_URL__c'] = $CFG->wwwroot.'/mod/assign/view.php?id='.$eventdata['contextinstanceid'];
        $queuedata['Submission_Date__c'] = date("Y-m-d\TH:i:s", $eventdata['timecreated']);

        $endpoint = get_config('local_tlconnect','endpointurl').'/services/data/v53.0/sobjects/Assessment__c';
        $logdata = 'assessable submitted event user: '.$user->username .' course:'.$course->shortname;
        self::write_queuedata($queuedata, 'assessable_submitted', $logdata, $endpoint);

    }

    /**
     * When an assessor is allocated to mark an assignment
     *
     * @param marker_updated $event
     * @return void
     */
    public static function marker_updated(\mod_assign\event\marker_updated $event) {
        global $DB;
        $queuedata  = [];
        $eventdata = $event->get_data();

        $course = $DB->get_record('course', ['id' => $eventdata['courseid']]);
        if ($course->idnumber == "") {
            return;
        }
        $candidate = $DB->get_record('user', ['id' => $eventdata['relateduserid']]);
        $modinfo = get_fast_modinfo($eventdata['courseid']);
        $assign  = $modinfo->get_cm($eventdata['contextinstanceid']);
        $markerid = $eventdata['other']['markerid'];
        $assessor = $DB->get_record('user', ['id' => $markerid]);

        $queuedata['Assessor__r']['APIID__c'] = $assessor->username;
        $endpoint = get_config('local_tlconnect','endpointurl').'/services/data/v53.0/sobjects/Assessment__c/Assessment_ID__c/';
        $endpoint .= $candidate->username.'_'.$assign->idnumber;

        $logdata = 'marker_updated event candidate: '.$candidate->username .' marker:'.$assessor->username.' course:'.$course->shortname. ' assign:'.$assign->name;
        self::write_queuedata($queuedata, 'marker_updated', $logdata, $endpoint, api::METHOD_PATCH);
    }

    /**
     *
     * When workflow has been updated
     *
     * @param \mod_assign\event\workflow_state_updated $event
     * @return void
     */
    public static function workflow_state_updated(\mod_assign\event\workflow_state_updated $event) {
        $eventdata = $event->get_data();
        self::grade_event($eventdata,'workflow_state_updated');
    }
    /**
     * Deal with both workflow state updating and grading
     *
     * @param array $eventdata
     * @param string $eventname
     * @return void
     */
    public static function grade_event(array $eventdata, string $eventname) {
        global $DB;
        $course = $DB->get_record('course', ['id' => $eventdata['courseid']]);
        if ($course->idnumber == "") {
            return;
        }
        $candidate = $DB->get_record('user', ['id' => $eventdata['relateduserid']]);
        $modinfo = get_fast_modinfo($eventdata['courseid']);
        $assign  = $modinfo->get_cm($eventdata['contextinstanceid']);
        $workflowstate = $eventdata['other']['newstate'] ?? null;

        $params = [
            'assignment' => $assign->instance,
            'attemptnumber' => 0,
            'userid' => $candidate->id,
        ];
        $grades = $DB->get_records('assign_grades', $params, 'attemptnumber DESC', '*', 0, 1);
        $grade = reset($grades)->grade;

        // If gradeoutof is less than zero it must be a custom scale.
        // Get the scale and convert the sale to matching string.
        $gradeoutof = $DB->get_field('assign', 'grade', ['id'=> $assign->instance]);
        if($gradeoutof < 0) {
            $scale = $DB->get_record('scale', ['id'=> -($gradeoutof)],'scale');
            $scaleoptions =  make_menu_from_list($scale->scale);
            $grade = $scaleoptions[(int) $grade];
        }

        $userflags = $DB->get_record('assign_user_flags', ['userid' => $candidate->id, 'assignment' => $assign->instance]);
        if(!$workflowstate) {
            $workflowstate = $userflags->workflowstate;
        }
        $assessor = $DB->get_record('user', ['id' => $userflags->allocatedmarker]);

        $queuedata['Assigned_Grade__c'] = $grade;
        $queuedata['Assessor__r']['APIID__c'] = $assessor->username;
        $queuedata['Course__c'] = $course->idnumber;

        if ($workflowstate == ASSIGN_MARKING_WORKFLOW_STATE_INMARKING) {
            $queuedata['Marking_Workflow_Status__c'] = 'Marking';
        } else if ($workflowstate == ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW) {
            $queuedata['Marking_Workflow_Status__c'] = 'Completed';
        } else if ($workflowstate == ASSIGN_MARKING_WORKFLOW_STATE_INREVIEW) {
            $queuedata['Marking_Workflow_Status__c'] = 'In review';
        } else if ($workflowstate == ASSIGN_MARKING_WORKFLOW_STATE_READYFORRELEASE) {
            $queuedata['Marking_Workflow_Status__c'] = 'Ready for release';
        } else if ($workflowstate == ASSIGN_MARKING_WORKFLOW_STATE_RELEASED) {
            $queuedata['Marking_Workflow_Status__c'] = 'Released';
        }
        $endpoint = get_config('local_tlconnect','endpointurl').'/services/data/v53.0/sobjects/Assessment__c/Assessment_ID__c/';
        $endpoint .= $candidate->username.'_'.$assign->idnumber;

        $logdata = $eventname.'  :'.$workflowstate. ' user:'.$candidate->username. ' assignment:'. $assign->name .' course:'.$course->shortname;

        self::write_queuedata($queuedata, 'grade_event', $logdata, $endpoint, api::METHOD_PATCH);

    }
    /**
     * Marking/Grading an assignment submission
     *
     * @param \mod_assign\event\submission_graded $event
     * @return void
     */
    public static function submission_graded(\mod_assign\event\submission_graded $event){
        $eventdata = $event->get_data();
        self::grade_event($eventdata, 'submission_graded');
    }
    /**
     * Write to the database for either sending on the cron or
     * sending immediatly.
     * @param array $queuedata
     * @return void
     */
    public static function write_queuedata(array $queuedata, $eventname, $logdata, $endpoint, $method = api::METHOD_POST_JSON ) {
        global $DB;
        $jsondata = json_encode($queuedata);
        $dataobject = (object) [
                'event' => $eventname,
                'data' => $jsondata,
                'adapter' => '1',
                'timecreated' => time()
        ];

        $DB->insert_record('local_datasender_queue', $dataobject);
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            return true;
        }
        $api = api::instance();
        $api->call($endpoint, $queuedata, $method, $logdata);

    }

}
