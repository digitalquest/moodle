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
 * Data generators tests
 *
 * @package    core
 * @category   phpunit
 * @copyright  2012 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();


/**
 * Test data generator
 *
 * @package    core
 * @category   phpunit
 * @copyright  2012 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_test_generator_testcase extends advanced_testcase {
    public function test_get_plugin_generator_good_case() {
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $this->assertInstanceOf('core_question_generator', $generator);
    }

    public function test_get_plugin_generator_sloppy_name() {
        $generator = $this->getDataGenerator()->get_plugin_generator('quiz');
        $this->assertDebuggingCalled('Please specify the component you want a generator for as ' .
                    'mod_quiz, not quiz.', DEBUG_DEVELOPER);
        $this->assertInstanceOf('mod_quiz_generator', $generator);
    }

    public function test_get_plugin_generator_no_component_dir() {
        $this->setExpectedException('coding_exception', 'Component core_completion does not support ' .
                    'generators yet. Missing tests/generator/lib.php.');
        $generator = $this->getDataGenerator()->get_plugin_generator('core_completion');
    }

    public function test_create() {
        global $DB;

        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $count = $DB->count_records('user');
        $user = $generator->create_user();
        $this->assertEquals($count+1, $DB->count_records('user'));
        $this->assertSame($user->username, clean_param($user->username, PARAM_USERNAME));
        $this->assertSame($user->email, clean_param($user->email, PARAM_EMAIL));
        $user = $generator->create_user(array('firstname'=>'Žluťoučký', 'lastname'=>'Koníček'));
        $this->assertSame($user->username, clean_param($user->username, PARAM_USERNAME));
        $this->assertSame($user->email, clean_param($user->email, PARAM_EMAIL));

        $count = $DB->count_records('course_categories');
        $category = $generator->create_category();
        $this->assertEquals($count+1, $DB->count_records('course_categories'));
        $this->assertRegExp('/^Course category \d/', $category->name);
        $this->assertSame('', $category->idnumber);
        $this->assertRegExp('/^Test course category \d/', $category->description);
        $this->assertSame(FORMAT_MOODLE, $category->descriptionformat);

        $count = $DB->count_records('cohort');
        $cohort = $generator->create_cohort();
        $this->assertEquals($count+1, $DB->count_records('cohort'));
        $this->assertEquals(context_system::instance()->id, $cohort->contextid);
        $this->assertRegExp('/^Cohort \d/', $cohort->name);
        $this->assertSame('', $cohort->idnumber);
        $this->assertRegExp('/^Test cohort \d/', $cohort->description);
        $this->assertSame(FORMAT_MOODLE, $cohort->descriptionformat);
        $this->assertSame('', $cohort->component);
        $this->assertLessThanOrEqual(time(), $cohort->timecreated);
        $this->assertSame($cohort->timecreated, $cohort->timemodified);

        $count = $DB->count_records('course');
        $course = $generator->create_course();
        $this->assertEquals($count+1, $DB->count_records('course'));
        $this->assertRegExp('/^Test course \d/', $course->fullname);
        $this->assertRegExp('/^tc_\d/', $course->shortname);
        $this->assertSame('', $course->idnumber);
        $this->assertSame('topics', $course->format);
        $this->assertEquals(0, $course->newsitems);
        $this->assertEquals(5, $course->numsections);
        $this->assertRegExp('/^Test course \d/', $course->summary);
        $this->assertSame(FORMAT_MOODLE, $course->summaryformat);

        $section = $generator->create_course_section(array('course'=>$course->id, 'section'=>3));
        $this->assertEquals($course->id, $section->course);

        $scale = $generator->create_scale();
        $this->assertNotEmpty($scale);

        // Note we only count grade cats with depth > 1 because the course grade category
        // is lazily created.
        $count = $DB->count_records_select('grade_categories', 'depth <> 1');
        $gradecategory = $generator->create_grade_category(array('courseid'=>$course->id));
        $this->assertEquals($count+1, $DB->count_records_select('grade_categories', 'depth <> 1'));
        $this->assertEquals(2, $gradecategory->depth);
        $this->assertEquals($course->id, $gradecategory->courseid);
        $this->assertEquals('Grade category 1', $gradecategory->fullname);
    }

    public function test_create_module() {
        global $CFG, $SITE, $DB;
        if (!file_exists("$CFG->dirroot/mod/page/")) {
            $this->markTestSkipped('Can not find standard Page module');
        }

        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $page = $generator->create_module('page', array('course'=>$SITE->id));
        $this->assertNotEmpty($page);
        $cm = get_coursemodule_from_instance('page', $page->id, $SITE->id, true);
        $this->assertEquals(0, $cm->sectionnum);

        $page = $generator->create_module('page', array('course'=>$SITE->id), array('section'=>3));
        $this->assertNotEmpty($page);
        $cm = get_coursemodule_from_instance('page', $page->id, $SITE->id, true);
        $this->assertEquals(3, $cm->sectionnum);

        // Prepare environment to generate modules with all possible options.

        // Enable advanced functionality.
        $CFG->enablecompletion = 1;
        $CFG->enableavailability = 1;
        $CFG->enablegroupmembersonly = 1;
        $CFG->enableoutcomes = 1;
        require_once($CFG->libdir.'/gradelib.php');
        require_once($CFG->libdir.'/completionlib.php');
        require_once($CFG->dirroot.'/rating/lib.php');

        // Create a course with enabled completion.
        $course = $generator->create_course(array('enablecompletion' => true));

        // Create new grading category in this course.
        $grade_category = new grade_category();
        $grade_category->courseid = $course->id;
        $grade_category->fullname = 'Grade category';
        $grade_category->insert();

        // Create group and grouping.
        $group = $generator->create_group(array('courseid' => $course->id));
        $grouping = $generator->create_grouping(array('courseid' => $course->id));
        $generator->create_grouping_group(array('groupid' => $group->id, 'groupingid' => $grouping->id));

        // Prepare arrays with properties that we can both use for creating modules and asserting the data in created modules.

        // General properties.
        $optionsgeneral = array(
            'visible' => 0, // Note: 'visibleold' will always be set to the same value as 'visible'.
            'section' => 3, // Note: section will be created if does not exist.
            // Module supports FEATURE_IDNUMBER.
            'cmidnumber' => 'IDNUM', // Note: alternatively can have key 'idnumber'.
            // Module supports FEATURE_GROUPS;
            'groupmode' => SEPARATEGROUPS, // Note: will be reset to 0 if course groupmodeforce is set.
            // Module supports FEATURE_GROUPINGS or module supports FEATURE_GROUPMEMBERSONLY:
            'groupingid' => $grouping->id,
            // Module supports FEATURE_GROUPMEMBERSONLY:
            'groupmembersonly' => 1,
        );

        // In case completion is enabled on site and for course every module can have manual completion.
        $featurecompletionmanual = array(
            'completion' => COMPLETION_TRACKING_MANUAL, // "Students can manually mark activity as completed."
            'completionexpected' => time() + 7 * DAYSECS,
        );

        // Automatic completion is possible if module supports FEATURE_COMPLETION_TRACKS_VIEWS or FEATURE_GRADE_HAS_GRADE.
        // Note: completionusegrade is stored in DB and can be found in cm_info as 'completiongradeitemnumber' - either NULL or 0.
        // Note: module can have more autocompletion rules as defined in moodleform_mod::add_completion_rules().
        $featurecompletionautomatic = array(
            'completion' => COMPLETION_TRACKING_AUTOMATIC, // "Show activity as complete when conditions are met."
            'completionview' => 1, // "Student must view this activity to complete it"
            'completionusegrade' => 1, // "Student must receive a grade to complete this activity"
        );

        // Module supports FEATURE_RATE:
        $featurerate = array(
            'assessed' => RATING_AGGREGATE_AVERAGE, // "Aggregate type"
            'scale' => 100, // Either max grade or negative number for scale id.
            'ratingtime' => 1, // "Restrict ratings to items with dates in this range".
            'assesstimestart' => time() - DAYSECS, // Note: Will be ignored if neither 'assessed' nor 'ratingtime' is set.
            'assesstimefinish' => time() + DAYSECS, // Note: Will be ignored if neither 'assessed' nor 'ratingtime' is set.
        );

        // Module supports FEATURE_GRADE_HAS_GRADE:
        $featuregrade = array(
            'grade' => 10,
            'gradecat' => $grade_category->id, // Note: if $CFG->enableoutcomes is set, this can be set to -1 to automatically create new grade category.
        );

        // Now let's create several modules with different options.
        $m1 = $generator->create_module('assign',
            array('course' => $course->id) +
            $optionsgeneral);
        $m2 = $generator->create_module('data',
            array('course' => $course->id) +
            $featurecompletionmanual +
            $featurerate);
        $m3 = $generator->create_module('assign',
            array('course' => $course->id) +
            $featurecompletionautomatic +
            $featuregrade);

        // We need id of the grading item for the second module to create availability dependency in the 3rd module.
        $gradingitem = grade_item::fetch(array('courseid'=>$course->id, 'itemtype'=>'mod', 'itemmodule' => 'assign', 'iteminstance' => $m3->id));

        // Now prepare options to create the 4th module which availability depends on other modules.
        // Following options available if $CFG->enableavailability is set:
        $optionsavailability = array(
            'showavailability' => 1,
            'availablefrom' => time() - WEEKSECS,
            'availableuntil' => time() + WEEKSECS,
            'conditiongradegroup' => array(
                array(
                    'conditiongradeitemid' => $gradingitem->id,
                    'conditiongrademin' => 20,
                    'conditiongrademax' => 80,
                )
            ),
            'conditionfieldgroup' => array(
                array(
                    'conditionfield' => 'address',
                    'conditionfieldoperator' => 'contains',
                    'conditionfieldvalue' => 'street',
                )
            ),
            'conditioncompletiongroup' => array(
                array(
                    'conditionsourcecmid' => $m2->cmid,
                    'conditionrequiredcompletion' => 1
                ),
                array(
                    'conditionsourcecmid' => $m3->cmid,
                    'conditionrequiredcompletion' => 1
                )
            )
        );
        // The same data for assertion (different format).
        $optionsavailabilityassertion = array(
            'conditionsgrade' => array(
                $gradingitem->id => (object)array(
                    'min' => 20,
                    'max' => 80,
                    'name' => $gradingitem->itemname
                )
            ),
            'conditionsfield' => array(
                'address' => (object)array(
                    'fieldname' => 'address',
                    'operator' => 'contains',
                    'value' => 'street'
                )
            ),
            'conditionscompletion' => array(
                $m2->cmid => 1,
                $m3->cmid => 1
            )
        );

        // Create module with conditional availability.
        $m4 = $generator->create_module('assign',
                array('course' => $course->id) +
                $optionsavailability
        );

        // Verifying that everything is generated correctly.
        $modinfo = get_fast_modinfo($course->id);
        $cm1 = $modinfo->cms[$m1->cmid];
        $this->assertEquals($optionsgeneral['visible'], $cm1->visible);
        $this->assertEquals($optionsgeneral['section'], $cm1->sectionnum); // Note difference in key.
        $this->assertEquals($optionsgeneral['cmidnumber'], $cm1->idnumber); // Note difference in key.
        $this->assertEquals($optionsgeneral['groupmode'], $cm1->groupmode);
        $this->assertEquals($optionsgeneral['groupingid'], $cm1->groupingid);
        $this->assertEquals($optionsgeneral['groupmembersonly'], $cm1->groupmembersonly);

        $cm2 = $modinfo->cms[$m2->cmid];
        $this->assertEquals($featurecompletionmanual['completion'], $cm2->completion);
        $this->assertEquals($featurecompletionmanual['completionexpected'], $cm2->completionexpected);
        $this->assertEquals(null, $cm2->completiongradeitemnumber);
        // Rating info is stored in the module's table (in our test {data}).
        $data = $DB->get_record('data', array('id' => $m2->id));
        $this->assertEquals($featurerate['assessed'], $data->assessed);
        $this->assertEquals($featurerate['scale'], $data->scale);
        $this->assertEquals($featurerate['assesstimestart'], $data->assesstimestart);
        $this->assertEquals($featurerate['assesstimefinish'], $data->assesstimefinish);
        // No validation for 'ratingtime'. It is only used in to enable/disable assesstime* when adding module.

        $cm3 = $modinfo->cms[$m3->cmid];
        $this->assertEquals($featurecompletionautomatic['completion'], $cm3->completion);
        $this->assertEquals($featurecompletionautomatic['completionview'], $cm3->completionview);
        $this->assertEquals(0, $cm3->completiongradeitemnumber); // Zero instead of default null since 'completionusegrade' was set.
        $gradingitem = grade_item::fetch(array('courseid'=>$course->id, 'itemtype'=>'mod', 'itemmodule' => 'assign', 'iteminstance' => $m3->id));
        $this->assertEquals(0, $gradingitem->grademin);
        $this->assertEquals($featuregrade['grade'], $gradingitem->grademax);
        $this->assertEquals($featuregrade['gradecat'], $gradingitem->categoryid);

        $cm4 = $modinfo->cms[$m4->cmid];
        $this->assertEquals($optionsavailability['showavailability'], $cm4->showavailability);
        $this->assertEquals($optionsavailability['availablefrom'], $cm4->availablefrom);
        $this->assertEquals($optionsavailability['availableuntil'], $cm4->availableuntil);
        $this->assertEquals($optionsavailabilityassertion['conditionsgrade'], $cm4->conditionsgrade);
        $this->assertEquals($optionsavailabilityassertion['conditionsfield'], $cm4->conditionsfield);
        $this->assertEquals($optionsavailabilityassertion['conditionscompletion'], $cm4->conditionscompletion);
    }

    public function test_create_block() {
        global $CFG;
        if (!file_exists("$CFG->dirroot/blocks/online_users/")) {
            $this->markTestSkipped('Can not find standard Online users block');
        }

        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();

        $page = $generator->create_block('online_users');
        $this->assertNotEmpty($page);
    }

    public function test_enrol_user() {
        global $DB;

        $this->resetAfterTest();

        $selfplugin = enrol_get_plugin('self');
        $this->assertNotEmpty($selfplugin);

        $manualplugin = enrol_get_plugin('manual');
        $this->assertNotEmpty($manualplugin);

        // Prepare some data.

        $studentrole = $DB->get_record('role', array('shortname'=>'student'));
        $this->assertNotEmpty($studentrole);
        $teacherrole = $DB->get_record('role', array('shortname'=>'teacher'));
        $this->assertNotEmpty($teacherrole);

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $course3 = $this->getDataGenerator()->create_course();

        $context1 = context_course::instance($course1->id);
        $context2 = context_course::instance($course2->id);
        $context3 = context_course::instance($course3->id);

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        $this->assertEquals(3, $DB->count_records('enrol', array('enrol'=>'self')));
        $instance1 = $DB->get_record('enrol', array('courseid'=>$course1->id, 'enrol'=>'self'), '*', MUST_EXIST);
        $instance2 = $DB->get_record('enrol', array('courseid'=>$course2->id, 'enrol'=>'self'), '*', MUST_EXIST);
        $instance3 = $DB->get_record('enrol', array('courseid'=>$course3->id, 'enrol'=>'self'), '*', MUST_EXIST);

        $this->assertEquals($studentrole->id, $instance1->roleid);
        $this->assertEquals($studentrole->id, $instance2->roleid);
        $this->assertEquals($studentrole->id, $instance3->roleid);

        $this->assertEquals(3, $DB->count_records('enrol', array('enrol'=>'manual')));
        $maninstance1 = $DB->get_record('enrol', array('courseid'=>$course1->id, 'enrol'=>'manual'), '*', MUST_EXIST);
        $maninstance2 = $DB->get_record('enrol', array('courseid'=>$course2->id, 'enrol'=>'manual'), '*', MUST_EXIST);
        $maninstance3 = $DB->get_record('enrol', array('courseid'=>$course3->id, 'enrol'=>'manual'), '*', MUST_EXIST);
        $maninstance3->roleid = $teacherrole->id;
        $DB->update_record('enrol', $maninstance3, array('id'=>$maninstance3->id));

        $this->assertEquals($studentrole->id, $maninstance1->roleid);
        $this->assertEquals($studentrole->id, $maninstance2->roleid);
        $this->assertEquals($teacherrole->id, $maninstance3->roleid);

        $result = $this->getDataGenerator()->enrol_user($user1->id, $course1->id);
        $this->assertTrue($result);
        $this->assertTrue($DB->record_exists('user_enrolments', array('enrolid'=>$maninstance1->id, 'userid'=>$user1->id)));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>$context1->id, 'userid'=>$user1->id, 'roleid'=>$studentrole->id)));

        $result = $this->getDataGenerator()->enrol_user($user1->id, $course2->id, $teacherrole->id, 'manual');
        $this->assertTrue($result);
        $this->assertTrue($DB->record_exists('user_enrolments', array('enrolid'=>$maninstance2->id, 'userid'=>$user1->id)));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>$context2->id, 'userid'=>$user1->id, 'roleid'=>$teacherrole->id)));

        $result = $this->getDataGenerator()->enrol_user($user1->id, $course3->id, 0, 'manual');
        $this->assertTrue($result);
        $this->assertTrue($DB->record_exists('user_enrolments', array('enrolid'=>$maninstance3->id, 'userid'=>$user1->id)));
        $this->assertFalse($DB->record_exists('role_assignments', array('contextid'=>$context3->id, 'userid'=>$user1->id)));

        $result = $this->getDataGenerator()->enrol_user($user2->id, $course1->id, null, 'self');
        $this->assertTrue($result);
        $this->assertTrue($DB->record_exists('user_enrolments', array('enrolid'=>$instance1->id, 'userid'=>$user2->id)));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>$context1->id, 'userid'=>$user2->id, 'roleid'=>$studentrole->id)));

        $selfplugin->add_instance($course2, array('status'=>ENROL_INSTANCE_ENABLED, 'roleid'=>$teacherrole->id));
        $result = $this->getDataGenerator()->enrol_user($user2->id, $course2->id, null, 'self');
        $this->assertFalse($result);

        $DB->delete_records('enrol', array('enrol'=>'self', 'courseid'=>$course3->id));
        $result = $this->getDataGenerator()->enrol_user($user2->id, $course3->id, null, 'self');
        $this->assertFalse($result);
    }
}
