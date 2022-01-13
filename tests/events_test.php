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
 * Event observer tests.
 *
 * @package    local_datasender
 * @author     Marcus Green
 * @copyright  (c) 2021 Titus Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();
global $CFG;

require_once($CFG->dirroot . '/question/engine/tests/helpers.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

require_once($CFG->dirroot . '/mod/assign/tests/generator.php');
require_once($CFG->dirroot . '/mod/assign/externallib.php');

class events_test extends advanced_testcase {
    // Use the generator helper.
    use mod_assign_test_generator;
    public function setUp() :void {
            set_config(
            'oauthurl',
            'https://test.salesforce.com/services/oauth2/authorize',
            'local_tlconnect'
        );
        set_config(
            'apiclientid',
            '3MVG9od6vNol.eBjLy0qL8CkyZl0C3MMHAuYncfrHhT9zLa0a60jC_oliAxPRfJQ0MuoefUWpCtH.hLQG2kO8',
            'local_tlconnect'
        );
        set_config(
            'apiclientsecret',
            'FEBE10A801551E86AB3DF5A9B24FCE7D16828646E6969DBD9837B8EC3A0721BD',
            'local_tlconnect'
        );
        set_config(
            'accesstokenurl',
            'https://test.salesforce.com/services/oauth2/token',
            'local_tlconnect'
        );

        set_config(
            'apiusername',
            'marcus.green@tituslearning.com.moodle',
            'local_tlconnect'
        );
        set_config(
            'apiuserpwd',
            'caution2drive',
            'local_tlconnect'
        );

    }
    public function test_marker_updated() {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course(['idnumber' => 'ID01']);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher', ['username' => 'johnsmith']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher->ignoresesskey = true;
        $this->setUser($teacher);
        $assign = $this->create_instance($course);
        $assign->testable_process_set_batch_marking_allocation($student->id, $teacher->id);

        $records = $DB->get_records('local_datasender_queue', ['event' => 'marker_updated']);
        $record = reset($records);
        $this->assertStringContainsString('Assessor__r', $record->data);
    }

    public function test_workflow_state_updated() {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course(['idnumber' => '27a2626000001cUrSAAU']);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student', ['idnumber' => '270032600000z8hG6AAI']);

        $teacher->ignoresesskey = true;
        $this->setUser($teacher);

        $assign = $this->create_instance($course);

        // Allocate marker to submission.
        $this->mark_submission($teacher, $assign, $student, null, [
            'allocatedmarker' => $teacher->id,
        ]);

        // Test process_set_batch_marking_workflow_state.
        $assign->testable_process_set_batch_marking_workflow_state($student->id, ASSIGN_MARKING_WORKFLOW_STATE_INREVIEW);

        $records = $DB->get_records('local_datasender_queue', ['event' => 'grade_event']);
        $record = reset($records);
        $this->assertStringContainsString('In review', $record->data);

        $DB->delete_records('local_datasender_queue');
        $assign->testable_process_set_batch_marking_workflow_state($student->id, ASSIGN_MARKING_WORKFLOW_STATE_INMARKING);
        $records = $DB->get_records('local_datasender_queue', ['event' => 'grade_event']);
        $record = reset($records);
        $this->assertStringContainsString('Marking', $record->data);
    }

    public function test_assessable_submitted() {
        $this->resetAfterTest();
        global $DB;

        $this->resetAfterTest(true);
        // Create a course and assignment and users.
        $course = self::getDataGenerator()->create_course(['idnumber' => 'a2626000001cQWQAA2']);

        set_config('submissionreceipts', 0, 'assign');
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $params['course'] = $course->id;
        $params['assignsubmission_onlinetext_enabled'] = 1;
        $params['submissiondrafts'] = 1;
        $params['sendnotifications'] = 0;
        $params['requiresubmissionstatement'] = 1;
        $instance = $generator->create_instance($params);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        $context = context_module::instance($cm->id);

        $assign = new assign($context, $cm, $course);
        $student = [
            'idnumber' => '0032600000z53RqAAI',
            'username' => 'johnsmith'
        ];

        $student = self::getDataGenerator()->create_user($student);
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));
        $this->getDataGenerator()->enrol_user($student->id,
                                                $course->id,
                                                $studentrole->id);

        // Create a student1 with an online text submission.
        // Simulate a submission.
        $this->setUser($student);
        $submission = $assign->get_user_submission($student->id, true);
        $data = new stdClass();
        $data->onlinetext_editor = array('itemid' => file_get_unused_draft_itemid(),
                                         'text' => 'Submission text',
                                         'format' => FORMAT_MOODLE);
        $plugin = $assign->get_submission_plugin_by_type('onlinetext');
        $plugin->save($submission, $data);
        mod_assign_external::submit_for_grading($instance->id, true);

        $records = $DB->get_records('local_datasender_queue');
        $record = reset($records);
        $this->assertStringContainsString('Date_Of_Enrolment__c', $record->data);

    }
    public function test_enrolment_assigned_event() {
        $this->resetAfterTest(true);
        global $DB;
        $user = $this->getDataGenerator()->create_user(['username' => 'johnsmith']);
        $course = $this->getDataGenerator()->create_course((['idnumber' => 'ID01']));
        // Triggers user_enrolment_created event.
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $records = $DB->get_records('local_datasender_queue');
        $record = reset($records);
        $this->assertStringContainsString('Date_Of_Enrolment__c', $record->data);
    }

    public function test_submit_quiz_event() {
        $this->resetAfterTest(true);
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');

        $quizdata = [
            'name' => 'Quiz 104',
            'course' => $course->id,
            'questionsperpage' => 0, 'grade' => 100.0,
            'sumgrades' => 2
        ];

         $quiz = $quizgenerator->create_instance($quizdata);
         $this->submit_quiz($quiz, $course);
         $records = $DB->get_records('local_datasender_queue');

         // No course idnumber means the event data is discardced.
        $this->assertEmpty($records);

        $course = $this->getDataGenerator()->create_course(['idnumber' => 'a2626000001cQWQAA2']);

        $quizdata['course'] = $course->id;
        $quiz = $quizgenerator->create_instance($quizdata);
        $this->submit_quiz($quiz, $course);
        $records = $DB->get_records('local_datasender_queue');
        $record = reset($records);

         $this->assertStringContainsString('APIID__c', $record->data);

    }
    public function submit_quiz($quiz, stdClass $course) {

        // Create a couple of questions.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');

        $cat = $questiongenerator->create_question_category();
        $saq = $questiongenerator->create_question('shortanswer', null, array('category' => $cat->id));
        $numq = $questiongenerator->create_question('numerical', null, array('category' => $cat->id));

        // Add them to the quiz.
        quiz_add_quiz_question($saq->id, $quiz);
        quiz_add_quiz_question($numq->id, $quiz);

        // Make a user to do the quiz.
        $user1 = $this->getDataGenerator()->create_user(['idnumber' => '0032600000z53QXAAY']);
        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'student');

        $quizobj = quiz::create($quiz->id, $user1->id);

        // Start the attempt.
        $quba = question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

        $timenow = time();
        $attempt = quiz_create_attempt($quizobj, 1, false, $timenow, false, $user1->id);

        quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $timenow);

        quiz_attempt_save_started($quizobj, $quba, $attempt);

        // Process some responses from the student.
        $attemptobj = quiz_attempt::create($attempt->id);

        $tosubmit = array(1 => array('answer' => 'frog'),
                          2 => array('answer' => '3.14'));

        $attemptobj->process_submitted_actions($timenow, false, $tosubmit);

        // Finish the attempt.
        $attemptobj = quiz_attempt::create($attempt->id);
        // This triggers the attempt submitted event.
        $attemptobj->process_finish($timenow, false);

    }

}
