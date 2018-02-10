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
	if (isset($_GET['id'])) {
		save_pres($db, $client, $_GET['id']);
	}
} else {
	callback();
}
function callback() {
	$redirect_uri = 'https://' . $_SERVER['HTTP_HOST'] . '/google-drive/callback';
	header('Location: ' . filter_var($redirect_uri, FILTER_SANITIZE_URL));
	exit();
}
function save_pres($db, $client, $presentation_id) {
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
	$stmt = $db->prepare('REPLACE INTO google_slides (api_id, num_slides, notes) VALUES (?, ?, ?)');
	$stmt->execute([$presentation_id, $num_slides, json_encode($presenter_notes)]);
	header('Location: ' . filter_var('https://' . $_SERVER['HTTP_HOST'] . '/google-drive/', FILTER_SANITIZE_URL));
	exit();
}
?>
<!doctype html>
<html lang="en">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
		<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css" integrity="sha384-Gn5384xqQ1aoWXA+058RXPxPg6fy4IWvTNh0E263XmFcJlSAwiGgFAW/dAiS6JXm" crossorigin="anonymous">

		<title>Google Drive API</title>
	</head>
	<body>
		<div class="container">
			<div class="row">
				<div class="col">
					<h2>Add another presentation</h2>
					<form class="form-inline" method="POST" action="">
						<label class="sr-only" for="pres_id">Presentation ID</label>
						<input type="text" class="form-control mb-2 mr-sm-2" id="pres_id" name="pres_id">
						<button type="submit" name="fetch-pres" class="btn btn-primary mb-2">Fetch Presentation</button>
					</form>
					<table class="table">
						<thead>
							<tr>
								<th>Presentation</th>
								<th>Slides</th>
								<th>Notes</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($db->query('SELECT api_id, num_slides, notes FROM google_slides') as $pres) {
								echo "<tr><td>{$pres['api_id']}</td><td>";
								for ($i=0; $i < $pres['num_slides']; $i++) {
									$padded = str_pad($i, 3, '0', STR_PAD_LEFT);
									echo "<img src='assets/{$pres['api_id']}/pres-{$padded}.jpg'>";
								}
								echo "</td><td><code>{$pres['notes']}</code></td></tr>";
							} ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>

		<script src="https://code.jquery.com/jquery-3.2.1.slim.min.js" integrity="sha384-KJ3o2DKtIkvYIK3UENzmM7KCkRr/rE9/Qpg6aAZGJwFDMVNA/GpGFF93hXpG5KkN" crossorigin="anonymous"></script>
		<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.9/umd/popper.min.js" integrity="sha384-ApNbgh9B+Y1QKtv3Rn7W3mgPxhU9K/ScQsAP7hUibX39j7fakFPskvXusvfa0b4Q" crossorigin="anonymous"></script>
		<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js" integrity="sha384-JZR6Spejh4U02d8jOt6vLEHfe/JQGiRRSQQxSfFWpi1MquVdAyjUar5+76PVCmYl" crossorigin="anonymous"></script>
	</body>
</html>