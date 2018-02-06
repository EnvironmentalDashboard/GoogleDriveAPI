<?php
require 'vendor/autoload.php';

$client = new Google_Client();
$client->setAuthConfig('secret/client_secret.json');
$client->addScope(Google_Service_Drive::DRIVE);

if (file_exists("credentials.json")) {
	$access_token = (file_get_contents("credentials.json"));
	$client->setAccessToken($access_token);
	//Refresh the token if it's expired.
	if ($client->isAccessTokenExpired()) {
		$client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
		file_put_contents($credentialsPath, json_encode($client->getAccessToken()));
	}
	$drive_service = new Google_Service_Drive($client);
	$files_list = $drive_service->files->listFiles(array())->getFiles(); 
	echo json_encode($files_list);
} else {
  $redirect_uri = 'http://' . $_SERVER['HTTP_HOST'] . '/GoogleDriveAPI/callback.php';
  header('Location: ' . filter_var($redirect_uri, FILTER_SANITIZE_URL));
}
?>