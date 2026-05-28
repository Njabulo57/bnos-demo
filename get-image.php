<?php
// get-image.php
error_reporting(0);

if (!isset($_GET['url'])) {
    http_response_code(400);
    die('No URL provided');
}

$url = $_GET['url'];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$imageBytes = curl_exec($ch);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    header("Content-Type: $contentType");
    header("Cache-Control: max-age=86400"); // Cache it for 24 hours
    echo $imageBytes;
} else {
    http_response_code($httpCode);
    echo "Image failed to load.";
}
?>