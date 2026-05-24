<?php
error_reporting(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$moodle_url = getenv('MOODLE_URL');
$token      = getenv('MOODLE_TOKEN');

$params = http_build_query([
    'wstoken' => $token,
    'moodlewsrestformat' => 'json',
    'wsfunction' => 'core_course_get_categories'
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $moodle_url . '?' . $params);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['ngrok-skip-browser-warning: true']);
$response = curl_exec($ch);

echo $response;
?>