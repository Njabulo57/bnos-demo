<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$moodle_url = getenv('MOODLE_URL');
$token      = getenv('MOODLE_TOKEN');

function callMoodleWS($moodle_url, $token, $wsfunction, $extraParams = []) {
    $params = array_merge([
        'wstoken'            => $token,
        'moodlewsrestformat' => 'json',
        'wsfunction'         => $wsfunction,
    ], $extraParams);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $moodle_url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['ngrok-skip-browser-warning: true']);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('cURL error: ' . $err);
    }
    curl_close($ch);

    $decoded = json_decode($response, true);
    if ($decoded === null) {
        throw new Exception('Moodle returned non-JSON for ' . $wsfunction . '. Raw: ' . substr($response, 0, 200));
    }

    return $decoded;
}

try {
    $courseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$courseId) throw new Exception('No course ID provided.');

    // ── 1. Primary source: timeline classification ──────────────────────────
    // This is the same endpoint used in get-course.php and reliably returns
    // courseimage, coursecategory, fullname, shortname, viewurl, progress
    $timelineResponse = callMoodleWS($moodle_url, $token,
        'core_course_get_enrolled_courses_by_timeline_classification',
        ['classification' => 'all']
    );

    if (isset($timelineResponse['exception'])) {
        throw new Exception('Timeline WS error: ' . $timelineResponse['message']);
    }

    $allCourses = $timelineResponse['courses'] ?? [];

    // Find the specific course by ID
    $timelineCourse = null;
    foreach ($allCourses as $c) {
        if ((int)$c['id'] === $courseId) {
            $timelineCourse = $c;
            break;
        }
    }

    if (!$timelineCourse) {
        throw new Exception('Course ID ' . $courseId . ' not found in enrolled courses.');
    }

    // ── 2. Supplement: core_course_get_courses for summary ──────────────────
    // The timeline endpoint doesn't return summary/description, so we fetch it
    // separately. If this fails we just use an empty summary — non-fatal.
    $summary = '';
    $supplementResponse = callMoodleWS($moodle_url, $token, 'core_course_get_courses', [
        'options[ids][0]' => $courseId,
    ]);
    if (!isset($supplementResponse['exception']) && !empty($supplementResponse[0])) {
        $summary = $supplementResponse[0]['summary'] ?? '';
    }

    // ── 3. Student count ────────────────────────────────────────────────────
    $usersResponse = callMoodleWS($moodle_url, $token, 'core_enrol_get_enrolled_users', [
        'courseid' => $courseId,
    ]);
    $studentCount = 0;
    if (is_array($usersResponse) && !isset($usersResponse['exception'])) {
        foreach ($usersResponse as $user) {
            foreach ($user['roles'] ?? [] as $role) {
                if ($role['shortname'] === 'student') {
                    $studentCount++;
                    break;
                }
            }
        }
    }

    // ── 4. Sections + modules (curriculum accordion) ────────────────────────
    $contentsResponse = callMoodleWS($moodle_url, $token, 'core_course_get_contents', [
        'courseid' => $courseId,
    ]);
    $sections    = [];
    $lessonCount = 0;

    if (is_array($contentsResponse) && !isset($contentsResponse['exception'])) {
        foreach ($contentsResponse as $section) {
            if (empty($section['modules'])) continue;

            $modules = [];
            foreach ($section['modules'] as $module) {
                $lessonCount++;
                $modules[] = [
                    'id'   => $module['id'],
                    'name' => $module['name'],
                    'url'  => $module['url'] ?? '',
                    'type' => $module['modname'] ?? 'resource',
                ];
            }

            $sections[] = [
                'id'      => $section['id'],
                'name'    => !empty($section['name']) ? $section['name'] : 'Section ' . ($section['section'] + 1),
                'summary' => $section['summary'] ?? '',
                'modules' => $modules,
            ];
        }
    }

    // ── 5. Course image — from timeline endpoint (most reliable) ────────────
    $courseImage = null;
    if (!empty($timelineCourse['courseimage'])) {
        $courseImage = 'get-image.php?url=' . urlencode($timelineCourse['courseimage']);
    }

    ob_clean();
    echo json_encode([
        'status' => 'success',
        'course' => [
            'id'           => $courseId,
            'fullname'     => $timelineCourse['fullname'],
            'shortname'    => $timelineCourse['shortname'],
            'summary'      => $summary,
            'categoryid'   => $timelineCourse['categoryid'] ?? null,
            'categoryname' => $timelineCourse['coursecategory'] ?? 'Uncategorized',
            'courseimage'  => $courseImage,
            'viewurl'      => $timelineCourse['viewurl'] ?? '',
            'progress'     => $timelineCourse['progress'] ?? 0,
            'studentCount' => $studentCount,
            'lessonCount'  => $lessonCount,
            'sections'     => $sections,
        ],
    ]);

} catch (Exception $e) {
    ob_clean();
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ]);
}
?>