<?php
require 'vendor/autoload.php';

$client = new Google_Client();
$client->setAuthConfig('secret/client_secret.json');
$client->addScope(Google_Service_Drive::DRIVE);

if (isset($_COOKIE['GoogleAPICredentials'])) {
	$access_token = $_COOKIE['GoogleAPICredentials'];
	$client->setAccessToken($access_token);
	//Refresh the token if it's expired.
	if ($client->isAccessTokenExpired()) {
		$client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
		setcookie("GoogleAPICredentials", json_encode($client->getAccessToken()), time()+(60*60*24*90), "/");
	}
	$drive_service = new Google_Service_Drive($client);
	$files_list = $drive_service->files->listFiles(array())->getFiles(); 
	echo json_encode($files_list);
} else {
  $redirect_uri = 'https://' . $_SERVER['HTTP_HOST'] . '/google-drive/callback';
  header('Location: ' . filter_var($redirect_uri, FILTER_SANITIZE_URL));
}
?>