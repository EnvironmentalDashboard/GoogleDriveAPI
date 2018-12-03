<?php
require '../vendor/autoload.php';
require '../../includes/db.php'; // has con to main db, $db
require '../../community-voices/src/CommunityVoices/App/Website/db.php'; // has con to cv db, $dbHandler
error_reporting(-1);
ini_set('display_errors', 'On');
// ini_set('xdebug.var_display_max_depth', '-1');
// ini_set('xdebug.var_display_max_children', '-1');
// ini_set('xdebug.var_display_max_data', '-1');

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
		insert_slides($dbHandler, read_slides($db, $client, $_POST['pid']));
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
	$slides_service = new Google_Service_Slides($client);
	$presentation = $slides_service->presentations->get($presentation_id);
	$slides = $presentation->getSlides();
	$num_slides = count($slides);
	// get content on slides
	$quotes = [];
	$attributions = [];
	$images = [];
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
		// determine which text is quote, which is attribution
		// $longer = (strlen($parts[0]) > strlen($parts[1])) ? 0 : 1;
		// $quotes[] = $parts[$longer];
		// $attributions[] = $parts[($longer ? 0 : 1)];
		if (strpos($parts[0], '“') !== false) { // $parts[0] has quote marks in it so it must be the quote
			$quotes[] = $parts[0];
			$attributions[] = $parts[1];
		} else { // $parts[1] is quote
			$quotes[] = $parts[1];
			$attributions[] = $parts[0];
		}
	}
	// get presenter notes
	$presenter_notes = [];
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
	return [$images, $quotes, $attributions, $presenter_notes];
}

function insert_slides($db, $slides) {
	$images = $slides[0];
	$quotes = $slides[1];
	$attributions = $slides[2];
	$presenter_notes = $slides[3];
	$count_im = count($images);
	$count_quo = count($quotes);
	$count_attr = count($attributions);
	$count_note = count($presenter_notes);
	if (!($count_im === $count_quo && $count_quo === $count_attr && $count_attr === $count_note)) {
		exit('Error parsing slides');
	}
	$contentCategories = [
    'serving our community' => 1,
    'our downtown' => 2,
    'next generation' => 3,
    'heritage' => 4,
    'natural oberlin' => 5,
    'neighbors' => 6,
  ];
	for ($i = 0; $i < $count_im; $i++) { 
		$note_parts = explode("\n", $presenter_notes[$i]);
		if (count($note_parts) < 5) {
			echo "Error parsing presenter notes for slide {$i} (has quote: {$quotes[$i]})\n<br />";
			continue;
		}
		// get image already in db
		$stmt = $db->prepare('SELECT media_id FROM `community-voices_images` WHERE filename = ?');
		$stmt->execute([substr($note_parts[0], 13)]); // cut off 'Image title: '
		$im_id = (int) $stmt->fetchColumn();
		if (!($im_id > 0)) {
			echo "Can't find image for slide {$i} (has quote: {$quotes[$i]})\n<br />";
			continue;
		}
		// get quote already in db
		$stmt = $db->prepare('SELECT media_id FROM `community-voices_quotes` WHERE text = ?');
		$stmt->execute([trim($quotes[$i], "“”. \t\n\r\0\x0B")]);
		$quo_id = (int) $stmt->fetchColumn();
		if (!($quo_id > 0)) {
			echo "Can't find quote for slide {$i} (has quote: {$quotes[$i]})\n<br />";
			continue;
		}
		$db->query("INSERT INTO `community-voices_media` (added_by, type, status) (1, 'slide', 'approved')");
		$slide_id = $db->lastInsertId();
		$stmt = $db->query("INSERT INTO `community-voices_slides` (media_id, content_category_id, image_id, quote_id, probability) VALUES (?, ?, ?, ?, ?)");
		$stmt->execute([
			$slide_id,
			intval($contentCategories[strtolower(trim($note_parts[5]))]),
			$im_id,
			$quo_id,
			intval(substr($note_parts[1], 13)) // cut off 'Probability: '
		]);
		// add tags
		foreach (explode(',', substr($note_parts[4], 6)) as $tag) { // example $note_parts[4]: 'Tags: People, Animal, Volunteering'
			$stmt = $db->prepare('SELECT id FROM `community-voices_groups` WHERE type = \'tag\' AND LOWER(label) = ?');
			$stmt->execute([trim($tag)]);
			$tag_id = (int) $stmt->fetchColumn();
			if (!($tag_id > 0)) {
				echo "Invalid tag {$tag} for slide {$i}\n";
				continue;
			}
			$stmt = $db->prepare('INSERT INTO `community-voices_media-group-map` (media_id, group_id) VALUES (?, ?)');
			$stmt->execute([$slide_id, $tag_id]);
		}
	}
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
		<input type="text" name="pid" placeholder="Presentation ID"><br />
		<input type="text" name="ccid" placeholder="Content Category ID">
		<input type="submit">
	</form>
</body>
</html>