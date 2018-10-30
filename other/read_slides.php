<?php
require '../vendor/autoload.php';
require '../../includes/db.php';
error_reporting(-1);
ini_set('display_errors', 'On');
ini_set('xdebug.var_display_max_depth', '-1');
ini_set('xdebug.var_display_max_children', '-1');
ini_set('xdebug.var_display_max_data', '-1');

$client = new Google_Client();
$client->setAuthConfig('../secret/client_secret.json');
$client->addScope(Google_Service_Drive::DRIVE);

if (isset($_COOKIE['GoogleAPICredentials'])) {
	$access_token = json_decode($_COOKIE['GoogleAPICredentials'], true);
	if (!is_array($access_token)) {
		callback();
	}
	$client->setAccessToken($access_token);
	if ($client->isAccessTokenExpired()) {
		$refresh_token = $client->getRefreshToken();
		$client->fetchAccessTokenWithRefreshToken($refresh_token);
		$new_access_token = $client->getAccessToken();
		$access_token = array_merge($access_token, $new_access_token);
    $client->setAccessToken($access_token);
		setcookie("GoogleAPICredentials", json_encode($access_token), time()+(60*60*24*1), "/");
	}
	if (isset($_POST['pid'])) {
		read_slides($db, $client, $_POST['pid']);
	}
} else {
	callback();
}
function callback() {
	$redirect_uri = 'https://' . $_SERVER['HTTP_HOST'] . '/google-drive/callback';
	header('Location: ' . filter_var($redirect_uri, FILTER_SANITIZE_URL));
	exit();
}
function read_slides($db, $client, $presentation_id) {
	// get presenter notes
	$presenter_notes = [];
	$quotes = [];
	$attributions = [];
	$images = [];
	$contentCategories = [];
	$slides_service = new Google_Service_Slides($client);
	$presentation = $slides_service->presentations->get($presentation_id);
	$slides = $presentation->getSlides();
	$num_slides = count($slides);
	for ($i = 0; $i < $num_slides; $i++) {
		$parts = [];
		foreach($slides[$i]->pageElements as $item) {
			if (isset($item->shape->text)) {
				$parts[] = $item->shape->text->textElements[1]->textRun->content;
			}
			if (isset($item->image)) { // && $i % 2 ?
				$images[] = $item->image->contentUrl;
			}
		}
		$longer = (strlen($parts[0]) > strlen($parts[1])) ? 0 : 1;
		$quotes[] = $parts[$longer];
		$attributions[] = $parts[($longer ? 0 : 1)];
	}
	var_dump($images);
	var_dump($quotes);
	var_dump($attributions);

}
?>


<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title>Document</title>
</head>
<body>
	<form action="" method="POST">
		<input type="text" name="pid" placeholder="Presentation ID">
		<input type="submit">
	</form>
</body>
</html>