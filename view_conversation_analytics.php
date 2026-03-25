<?php
/**
 * Teacher analytics page for chatbot conversations.
 *
 * @package    block_alma_ai_tutor
 */

require_once(__DIR__ . '/../../config.php');

global $DB;

$courseid = required_param('courseid', PARAM_INT);
$sectionid = optional_param('sectionid', 0, PARAM_INT);
$instanceid = optional_param('instanceid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$sessionid = optional_param('sessionid', 0, PARAM_INT);

$course = get_course($courseid);
require_login($course);

$context = context_course::instance($courseid);
require_capability('moodle/course:manageactivities', $context);

$baseurl = new moodle_url('/blocks/alma_ai_tutor/view_conversation_analytics.php', [
    'courseid' => $courseid,
    'sectionid' => $sectionid,
    'instanceid' => $instanceid,
]);

$PAGE->set_context($context);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('teacher_analytics_title', 'block_alma_ai_tutor'));
$PAGE->set_heading(format_string($course->fullname));

$backtourl = new moodle_url('/course/view.php', ['id' => $courseid]);

$scopesql = '';
$scopeparams = ['courseid' => $courseid];
if (!empty($sectionid)) {
    $scopesql .= ' AND s.sectionid = :sectionid';
    $scopeparams['sectionid'] = $sectionid;
}
if (!empty($instanceid)) {
    $scopesql .= ' AND s.instanceid = :instanceid';
    $scopeparams['instanceid'] = $instanceid;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('teacher_analytics_title', 'block_alma_ai_tutor'));
echo html_writer::div(
    html_writer::link($backtourl, get_string('backtocourse', 'block_alma_ai_tutor')),
    'mb-3'
);

if (empty($userid)) {
    $sql = "SELECT s.userid,
                   u.firstname,
                   u.lastname,
                   u.email,
                   COUNT(DISTINCT s.id) AS totalsessions,
                   COUNT(c.id) AS totalmessages,
                   MAX(s.timemodified) AS lastactivity
              FROM {block_alma_ai_tutor_chat_sessions} s
              JOIN {user} u ON u.id = s.userid
         LEFT JOIN {block_alma_ai_tutor_conversations} c ON c.sessionid = s.id
             WHERE s.courseid = :courseid {$scopesql}
          GROUP BY s.userid, u.firstname, u.lastname, u.email
          ORDER BY lastactivity DESC";

    $students = $DB->get_records_sql($sql, $scopeparams);

    if (empty($students)) {
        echo $OUTPUT->notification(get_string('teacher_analytics_no_data', 'block_alma_ai_tutor'), 'info');
        echo $OUTPUT->footer();
        exit;
    }

    $table = new html_table();
    $table->head = [
        get_string('student', 'block_alma_ai_tutor'),
        get_string('total_sessions', 'block_alma_ai_tutor'),
        get_string('total_messages', 'block_alma_ai_tutor'),
        get_string('last_activity', 'block_alma_ai_tutor'),
    ];

    foreach ($students as $student) {
        $studenturl = new moodle_url($baseurl, ['userid' => $student->userid]);
        $fullname = fullname((object)[
            'firstname' => $student->firstname,
            'lastname' => $student->lastname,
        ]) . ' (' . s($student->email) . ')';

        $table->data[] = [
            html_writer::link($studenturl, $fullname),
            (int)$student->totalsessions,
            (int)$student->totalmessages,
            userdate((int)$student->lastactivity),
        ];
    }

    echo html_writer::table($table);
    echo $OUTPUT->footer();
    exit;
}

$usersessionparams = $scopeparams;
$usersessionparams['userid'] = $userid;

if (empty($sessionid)) {
    $sql = "SELECT s.id,
                   s.title,
                   s.sectionid,
                   s.timecreated,
                   s.timemodified,
                   COUNT(c.id) AS totalmessages
              FROM {block_alma_ai_tutor_chat_sessions} s
         LEFT JOIN {block_alma_ai_tutor_conversations} c ON c.sessionid = s.id
             WHERE s.courseid = :courseid
               AND s.userid = :userid {$scopesql}
          GROUP BY s.id, s.title, s.sectionid, s.timecreated, s.timemodified
          ORDER BY s.timemodified DESC";

    $sessions = $DB->get_records_sql($sql, $usersessionparams);

    echo $OUTPUT->heading(get_string('student_sessions', 'block_alma_ai_tutor'), 3);

    if (empty($sessions)) {
        echo $OUTPUT->notification(get_string('teacher_analytics_no_student_sessions', 'block_alma_ai_tutor'), 'info');
        echo $OUTPUT->footer();
        exit;
    }

    $table = new html_table();
    $table->head = [
        get_string('session_title', 'block_alma_ai_tutor'),
        get_string('section', 'block_alma_ai_tutor'),
        get_string('total_messages', 'block_alma_ai_tutor'),
        get_string('last_activity', 'block_alma_ai_tutor'),
    ];

    foreach ($sessions as $session) {
        $sessionurl = new moodle_url($baseurl, [
            'userid' => $userid,
            'sessionid' => $session->id,
        ]);

        $table->data[] = [
            html_writer::link($sessionurl, s($session->title ?: get_string('untitled_session', 'block_alma_ai_tutor'))),
            (int)$session->sectionid,
            (int)$session->totalmessages,
            userdate((int)$session->timemodified),
        ];
    }

    echo html_writer::table($table);
    echo $OUTPUT->footer();
    exit;
}

$session = $DB->get_record('block_alma_ai_tutor_chat_sessions', [
    'id' => $sessionid,
    'userid' => $userid,
    'courseid' => $courseid,
], '*', MUST_EXIST);

$messages = $DB->get_records('block_alma_ai_tutor_conversations', ['sessionid' => $session->id], 'timecreated ASC');

echo $OUTPUT->heading(get_string('session_transcript', 'block_alma_ai_tutor'), 3);

echo html_writer::div(
    html_writer::link(new moodle_url($baseurl, ['userid' => $userid]), get_string('back_to_student_sessions', 'block_alma_ai_tutor')),
    'mb-3'
);

if (empty($messages)) {
    echo $OUTPUT->notification(get_string('teacher_analytics_no_messages', 'block_alma_ai_tutor'), 'info');
    echo $OUTPUT->footer();
    exit;
}

foreach ($messages as $message) {
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('student_question', 'block_alma_ai_tutor'), ['class' => 'card-title']);
    echo html_writer::div(format_text($message->question, FORMAT_PLAIN), 'mb-2');
    echo html_writer::tag('h5', get_string('chatbot_answer', 'block_alma_ai_tutor'), ['class' => 'card-title']);
    echo html_writer::div(format_text($message->answer, FORMAT_PLAIN), 'mb-2');
    echo html_writer::div(userdate((int)$message->timecreated), 'text-muted small');
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
