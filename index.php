<?php
require 'vendor/autoload.php';
require '../includes/db.php';
error_reporting(-1);
ini_set('display_errors', 'On');

if (isset($_POST['delete']) && $_POST['delete'] === 'Delete') {
	$stmt = $db->prepare('DELETE FROM google_pres WHERE id = ?');
	$stmt->execute([$_POST['pres_id']]);
	$stmt = $db->prepare('DELETE FROM google_slides WHERE pres_id = ?');
	$stmt->execute([$_POST['pres_id']]);
	array_map('unlink', glob("assets/{$_POST['api_id']}/*"));
	rmdir("assets/{$_POST['api_id']}");
}

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
		$refresh_token = $client->getRefreshToken();
		$client->fetchAccessTokenWithRefreshToken($refresh_token);
		$new_access_token = $client->getAccessToken();
		$access_token = array_merge($access_token, $new_access_token);
    $client->setAccessToken($access_token);
		setcookie("GoogleAPICredentials", json_encode($access_token), time()+(60*60*24*1), "/");
	}
	if (isset($_POST['pres_url'])) {
		$parsed = parse_url($_POST['pres_url'], PHP_URL_PATH);
		$parts = explode('/', $parsed);
		if (count($parts) < 4) {
			exit("Malformed Google presentation URL");
		}
		save_pres($db, $client, $parts[3]);
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
			$full_note = '';
			if ($obj->objectId === $note_id) {
				if ($obj->shape->text !== null) {
					foreach ($obj->shape->text->textElements as $textElem) {
						if (!isset($textElem->textRun)) {
							continue;
						}
						$full_note .= $textElem->textRun->content;
					}
				}
			}
			$presenter_notes[$i] = $full_note;
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
		$stmt = $db->prepare('DELETE FROM google_slides WHERE pres_id IN (SELECT id FROM google_pres WHERE api_id = ?)');
		$stmt->execute([$presentation_id]);
	} else {
		mkdir($assets . "/{$presentation_id}", 0755);
	}
	file_put_contents($assets . "/{$presentation_id}/pres.pdf", $content);
	shell_exec("convert -density 150 {$assets}/{$presentation_id}/pres.pdf {$assets}/{$presentation_id}/pres-%03d.jpg");
	$stmt = $db->prepare('REPLACE INTO google_pres (api_id, num_slides, notes) VALUES (?, ?, ?)');
	$stmt->execute([$presentation_id, $num_slides, json_encode($presenter_notes)]);
	$insert_id = $db->lastInsertId();
	foreach ($presenter_notes as $i => $note) {
		$note_contents = array_filter(explode("\n", $note));
		$title = trim(substr($note_contents[0], 12));
		$prob = intval(substr($note_contents[1], 12));
		$end_use = strtolower(trim(substr($note_contents[2], 8)));
		$tags = strtolower(trim(substr($note_contents[3], 5)));
		$category = strtolower(implode('-', explode(' ', trim($note_contents[4]))));
		try {
			$stmt = $db->prepare("INSERT INTO google_slides (pres_id, img, prob, end_use, url, category, tags) VALUES (?, ?, ?, ?, ?, ?, ?)");
			$stmt->execute([$insert_id, $title, $prob, $end_use, "https://environmentaldashboard.org/google-drive/assets/{$presentation_id}/pres-".str_pad($i, 3, '0', STR_PAD_LEFT).'.jpg', $category, $tags]);
		} catch (PDOException $e) {
			echo $e->getMessage() . "\n";
		}
	}
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
						<label class="sr-only" for="pres_url">Presentation URL</label>
						<input type="text" class="form-control mb-2 mr-sm-2" id="pres_url" name="pres_url" placeholder="https://docs.google.com/presentation/d/1B6dT93Zq4qwUbdqHvdk-g96M8y6cBu9Kz4PJC6c9FR4/edit..." style='width: 100%'>
						<button type="submit" name="fetch-pres" class="btn btn-primary mt-2 mb-2">Fetch Presentation</button>
					</form>
					<table class="table">
						<thead>
							<tr>
								<th>Presentation</th>
								<th>Slides</th>
								<th>Delete</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($db->query('SELECT id, api_id, num_slides FROM google_pres') as $pres) {
								if ($pres['api_id'] === 'photocache') {
									echo "<tr><td>photocache</td><td><p><a href='assets/photocache'>files</a></p></td><td>&nbsp;</td></tr>";
									continue;
								}
								echo "<tr><td>{$pres['api_id']}</td><td>";
								for ($i=0; $i < $pres['num_slides']; $i++) {
									$padded = str_pad($i, 3, '0', STR_PAD_LEFT);
									echo "<a href='assets/{$pres['api_id']}/pres-{$padded}.jpg'>Image {$padded}</a>\n";
								}
								echo "</td><td><form action='' method='POST'><input type='hidden' name='pres_id' value='{$pres['id']}' /><input type='hidden' name='api_id' value='{$pres['api_id']}' /><input type='submit' name='delete' value='Delete' class='btn btn-danger' /></form></td></tr>";
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