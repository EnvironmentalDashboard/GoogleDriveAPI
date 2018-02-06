<?php
require 'vendor/autoload.php';
error_reporting(-1);
ini_set('display_errors', 'On');

$client = new Google_Client();
$client->setAuthConfig('secret/client_secret.json');
$client->addScope(Google_Service_Drive::DRIVE);

if (isset($_COOKIE['GoogleAPICredentials'])) {
	$access_token = json_decode($_COOKIE['GoogleAPICredentials'], true);
	if (!is_array($access_token)) {
		callback();
	}
	$client->setAccessToken($access_token);
	if ($client->isAccessTokenExpired()) {
    $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
    setcookie("GoogleAPICredentials", json_encode($client->getAccessToken()), time()+(60*60*24*1), "/");
  }
	if (!isset($_GET['id'])) {
		exit("Provide a presentation id");
	} else {
		$presentationId = $_GET['id'];
	}

	// TO PRINT SLIDE PRESENTER NOTES
	// $service = new Google_Service_Slides($client);
	// $presentation = $service->presentations->get($presentationId);
	// $slides = $presentation->getSlides();
	// foreach ($slides as $slide) {
	// 	$note_id = $slide->slideProperties->notesPage->notesProperties->speakerNotesObjectId;
	// 	foreach ($slide->slideProperties->notesPage as $obj) {
	// 		if ($obj->objectId === $note_id) {
	// 			var_dump($obj->shape->text->textElements[1]->textRun->content);
	// 		}
	// 	}
	// }

	// TO OUTPUT PDF OF PRESENTATION
	// header('Content-Type: application/pdf');
	// $driveService = new Google_Service_Drive($client);
	// $response = $driveService->files->export($presentationId, 'application/pdf', array('alt' => 'media'));
	// $content = $response->getBody()->getContents();
	// echo $content;

	// have to iterate over individual slides to export as images; might be easier to convert PDFs to images on our end
	// for ($i=0; $i < count($slides); $i++) {
	// 	$id = $slides[$i]->objectId;
	// 	$export = 'https://docs.google.com/presentation/d/' + $presentationId +'/export/png?id=' + $presentationId + '&pageid=' + $id;
	// }
} else {
	callback();
}
function callback() {
	$redirect_uri = 'https://' . $_SERVER['HTTP_HOST'] . '/google-drive/callback';
  header('Location: ' . filter_var($redirect_uri, FILTER_SANITIZE_URL));
  exit();
}
?>