<?php
require 'vendor/autoload.php';
require '../includes/db.php';
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
		$presentation_id = $_GET['id'];
	}

	// get presenter notes
	$presenter_notes = [];
	$slides_service = new Google_Service_Slides($client);
	$presentation = $slides_service->presentations->get($presentation_id);
	$slides = $presentation->getSlides();
	$num_slides = count($slides);
	for ($i = 0; $i < $num_slides; $i++) {
		$note_id = $slides[$i]->slideProperties->notesPage->notesProperties->speakerNotesObjectId;
		$presenter_notes[$i] = null;
		foreach ($slides[$i]->slideProperties->notesPage as $obj) {
			if ($obj->objectId === $note_id) {
				if ($obj->shape->text !== null) {
					$presenter_notes[$i] = trim($obj->shape->text->textElements[1]->textRun->content);
				}
			}
		}
	}

	// get pdf of presentation
	$drive_service = new Google_Service_Drive($client);
	$response = $drive_service->files->export($presentation_id, 'application/pdf', array('alt' => 'media'));
	$content = $response->getBody()->getContents();
	$assets = __DIR__ . '/assets';
	if (!is_dir($assets)) {
		mkdir($assets, 0755);
	}
	if (is_dir($assets . "/{$presentation_id}")) {
		array_map('unlink', glob($assets . "/{$presentation_id}/*"));
	} else {
		mkdir($assets . "/{$presentation_id}", 0755);
	}
	file_put_contents($assets . "/{$presentation_id}/pres.pdf", $content);
	shell_exec("convert {$assets}/{$presentation_id}/pres.pdf {$assets}/{$presentation_id}/pres-%03d.jpg");
	$stmt = $db->prepare('INSERT INTO google_slides (api_id, num_slides, notes) VALUES (?, ?, ?)');
	$stmt->execute([$presentation_id, $num_slides, json_encode($presenter_notes)]);
} else {
	callback();
}
function callback() {
	$redirect_uri = 'https://' . $_SERVER['HTTP_HOST'] . '/google-drive/callback';
  header('Location: ' . filter_var($redirect_uri, FILTER_SANITIZE_URL));
  exit();
}
?>