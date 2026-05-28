<?php
// Buffer ALL output — catches any PHP warnings/notices that would corrupt JSON
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception('cURL error: ' . $error);
    }
    curl_close($ch);

    $decoded = json_decode($response, true);
    if ($decoded === null) {
        // Moodle returned non-JSON (e.g. an HTML redirect/error page)
        throw new Exception('Moodle returned non-JSON for ' . $wsfunction . '. Raw: ' . substr($response, 0, 200));
    }

    return $decoded;
}

try {
    $categoryName = isset($_GET['category']) ? trim($_GET['category']) : '';
    $excludeId    = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : 0;

    if (!$categoryName) {
        throw new Exception('No category provided.');
    }

    // 1. Fetch all enrolled courses
    $coursesResponse = callMoodleWS($moodle_url, $token,
        'core_course_get_enrolled_courses_by_timeline_classification',
        ['classification' => 'all']
    );

    if (isset($coursesResponse['exception'])) {
        throw new Exception('Moodle WS error: ' . $coursesResponse['message']);
    }

    $allCourses = $coursesResponse['courses'] ?? [];

    // 2. Filter: same category, not the current course, limit to 3
    $related = array_filter($allCourses, function ($c) use ($categoryName, $excludeId) {
        $sameCat = strcasecmp($c['coursecategory'] ?? '', $categoryName) === 0;
        $notSelf = (int)$c['id'] !== $excludeId;
        return $sameCat && $notSelf;
    });

    $related = array_slice(array_values($related), 0, 3);

    // 3. Enrich each related course
    $enriched = [];
    foreach ($related as $course) {
        $courseId = $course['id'];

        // Student count
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

        // Lesson count
        $contentsResponse = callMoodleWS($moodle_url, $token, 'core_course_get_contents', [
            'courseid' => $courseId,
        ]);
        $lessonCount = 0;
        if (is_array($contentsResponse) && !isset($contentsResponse['exception'])) {
            foreach ($contentsResponse as $section) {
                if (isset($section['modules'])) {
                    $lessonCount += count($section['modules']);
                }
            }
        }

        $courseImage = null;
        if (!empty($course['courseimage'])) {
            $courseImage = 'get-image.php?url=' . urlencode($course['courseimage']);
        }

        $enriched[] = [
            'id'           => $courseId,
            'fullname'     => $course['fullname'],
            'categoryname' => $course['coursecategory'] ?? $categoryName,
            'courseimage'  => $courseImage,
            'viewurl'      => $course['viewurl'] ?? '',
            'studentCount' => $studentCount,
            'lessonCount'  => $lessonCount,
        ];
    }

    // Discard any accidental output before sending JSON
    ob_clean();
    echo json_encode([
        'status'  => 'success',
        'total'   => count($enriched),
        'courses' => $enriched,
    ]);

} catch (Exception $e) {
    ob_clean();
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ]);
}