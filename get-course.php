<?php
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$moodle_url = getenv('MOODLE_URL');
$token      = getenv('MOODLE_TOKEN');

// Helper: makes a POST request to Moodle WS
function callMoodleWS($moodle_url, $token, $wsfunction, $extraParams = []) {
    $params = array_merge([
        'wstoken'             => $token,
        'moodlewsrestformat'  => 'json',
        'wsfunction'          => $wsfunction,
    ], $extraParams);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $moodle_url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

try {
    // 1. Get enrolled courses via timeline classification
    $coursesResponse = callMoodleWS($moodle_url, $token,
        'core_course_get_enrolled_courses_by_timeline_classification',
        ['classification' => 'all']
    );

    if (isset($coursesResponse['exception'])) {
        throw new Exception($coursesResponse['message']);
    }

    $courses = $coursesResponse['courses'] ?? [];
    $enrichedCourses = [];

    // 2. Enrich each course with student count and lesson count
    foreach ($courses as $course) {
        $courseId = $course['id'];

        // --- Student count (filter to role = student only) ---
        $usersResponse = callMoodleWS($moodle_url, $token,
            'core_enrol_get_enrolled_users',
            ['courseid' => $courseId]
        );

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

        // --- Lesson/module count ---
        $contentsResponse = callMoodleWS($moodle_url, $token,
            'core_course_get_contents',
            ['courseid' => $courseId]
        );

        $lessonCount = 0;
        if (is_array($contentsResponse) && !isset($contentsResponse['exception'])) {
            foreach ($contentsResponse as $section) {
                if (isset($section['modules']) && is_array($section['modules'])) {
                    $lessonCount += count($section['modules']);
                }
            }
        }

        // 3. Build enriched course object
        // Note: this WS function already returns real `progress` per user
        $enrichedCourses[] = [
            'id'           => $courseId,
            'fullname'     => $course['fullname'],
            'shortname'    => $course['shortname'],
            'categoryname' => $course['coursecategory'] ?? 'Uncategorized',
            'courseimage'  => isset($course['courseimage']) ? 'get-image.php?url=' . urlencode($course['courseimage']) : null,
            'viewurl'      => $course['viewurl'] ?? '',
            'progress'     => $course['progress'] ?? 0,
            'studentCount' => $studentCount,
            'lessonCount'  => $lessonCount,
        ];
    }

    echo json_encode([
        'status'  => 'success',
        'total'   => count($enrichedCourses),
        'courses' => $enrichedCourses,
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ]);
}
?>