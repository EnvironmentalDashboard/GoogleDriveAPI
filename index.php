<?php
require 'vendor/autoload.php';
error_reporting(-1);
ini_set('display_errors', 'On');

$client = new Google_Client();
$client->setAuthConfig('secret/client_secret.json');
$client->addScope(Google_Service_Drive::DRIVE);

if (isset($_COOKIE['GoogleAPICredentials'])) {
	$access_token = $_COOKIE['GoogleAPICredentials'];
	$client->setAccessToken($access_token);
	if ($client->isAccessTokenExpired()) {
		$client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
		$access_token2 = $client->getAccessToken();
    $access_token = array_merge($access_token, $access_token2);
		setcookie("GoogleAPICredentials", json_encode($access_token), time()+(60*60*24*90), "/");
	}
	if (!isset($_GET['id'])) {
		exit("Provide a presentation id");
	} else {
		$presentationId = $_GET['id'];
	}
	// $service = new Google_Service_Slides($client);
	// $presentation = $service->presentations->get($presentationId);
	// $slides = $presentation->getSlides();
	// foreach ($slides as $i => $slide) {
	//   // Print columns A and E, which correspond to indices 0 and 4.
	//   printf("- Slide #%s contains %s elements.\n", $i + 1, count($slide->getPageElements()));
	// }
	// for ($i=0; $i < count($slides); $i++) {
	// 	$id = $slides[$i]->objectId;
	// 	$export = 'https://docs.google.com/presentation/d/' + $presentationId +'/export/png?id=' + $presentationId + '&pageid=' + $id;
	// }
	header('Content-Type: application/pdf');
	$driveService = new Google_Service_Drive($client);
	$response = $driveService->files->export($presentationId, 'application/pdf', array(
	    'alt' => 'media'));
	$content = $response->getBody()->getContents();
	echo $content;
} else {
  $redirect_uri = 'https://' . $_SERVER['HTTP_HOST'] . '/google-drive/callback';
  header('Location: ' . filter_var($redirect_uri, FILTER_SANITIZE_URL));
}
?>