<?php
require_once('config.php');
require_once('UpdateMe.php');

$up = new UpdateMe($config);

/*** usage example:
if ($updated_version = $up->check_update()) {
	if ($up->get_patch_file($updated_version)) {
		$up->update($updated_version);
		echo "Updated to $updated_version";
	} else {
		echo "Patch not found in server";
	}
} else {
	echo "Already update";
}

// $up->rollback();

/**/