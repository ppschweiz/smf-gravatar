<?php

function smf_ppsgravatar_download($memID) {
	global $modSettings, $sourcedir, $smcFunc, $profile_vars, $cur_profile, $context, $user_settings;
	if (!is_array($cur_profile)) $cur_profile = $user_settings;

	require_once($sourcedir . '/ManageAttachments.php');

	// We need to know where we're going to be putting it..
	if (!empty($modSettings['custom_avatar_enabled'])) {
		$uploadDir = $modSettings['custom_avatar_dir'];
		$id_folder = 1;
	} else if (!empty($modSettings['currentAttachmentUploadDir'])) {
		if (!is_array($modSettings['attachmentUploadDir'])) {
			$modSettings['attachmentUploadDir'] = unserialize($modSettings['attachmentUploadDir']);
		}
		// Just use the current path for temp files.
		$uploadDir = $modSettings['attachmentUploadDir'][$modSettings['currentAttachmentUploadDir']];
		$id_folder = $modSettings['currentAttachmentUploadDir'];
	} else {
		$uploadDir = $modSettings['attachmentUploadDir'];
		$id_folder = 1;
	}

	if (!is_writable($uploadDir)) {
		fatal_lang_error('attachments_no_write', 'critical');
	}

	require_once($sourcedir . '/Subs-Package.php');
	$url = 'http://www.gravatar.com/avatar.php?gravatar_id=' . md5($cur_profile['email_address']) . (!empty($modSettings['gravatar_rating']) ? '&rating=' . $modSettings['gravatar_rating']: '') . (!empty($modSettings['gravatar_default']) ? '&default=' . urlencode($modSettings['gravatar_default']) : '');
	$contents = fetch_web_data($url);

	$tmpFile = $uploadDir.'/avatar_tmp_'.$memID;
	if (($contents != false) && ($tmpAvatar = fopen($tmpFile, 'wb'))) {
		fwrite($tmpAvatar, $contents);
		fclose($tmpAvatar);
		chmod($tmpFile, 0644);

		$sizes = getimagesize($tmpFile);
		// No size, then it's probably not a valid pic.
		if ($sizes === false) {
			return 'bad_avatar';
		}

		if ( (!empty($modSettings['avatar_max_width_upload']) && ($sizes[0] > $modSettings['avatar_max_width_upload'])) || (!empty($modSettings['avatar_max_height_upload']) && ($sizes[1] > $modSettings['avatar_max_height_upload'])) ) {
			if (!empty($modSettings['avatar_resize_upload'])) {
				require_once($sourcedir . '/Subs-Graphics.php');
				if (!downloadAvatar($tmpFile, $memID, $modSettings['avatar_max_width_upload'], $modSettings['avatar_max_height_upload'])) {
					return 'bad_avatar';
				}

				// Reset attachment avatar data.
				$cur_profile['id_attach'] = $modSettings['new_avatar_data']['id'];
				$cur_profile['filename'] = $modSettings['new_avatar_data']['filename'];
				$cur_profile['attachment_type'] = $modSettings['new_avatar_data']['type'];
			} else {
				return 'bad_avatar';
			}
		} else {
			require_once($sourcedir . '/Subs-Graphics.php');
			if (!checkImageContents($tmpFile, !empty($modSettings['avatar_paranoid']))) {
				// It's bad. Try to re-encode the contents?
				if (empty($modSettings['avatar_reencode']) || (!reencodeImage($tmpFile, $sizes[2]))) {
					return 'bad_avatar';
				}

				// We were successful. However, at what price?
				$sizes = getimagesize($tmpFile);
				// Hard to believe this would happen, but can you bet?
				if ($sizes === false) {
					return 'bad_avatar';
				}
			}

			// Try to get the image extension, mime and destname
			$extensions = array(
				'1' => 'gif',
				'2' => 'jpg',
				'3' => 'png',
				'6' => 'bmp'
			);
			$extension = isset($extensions[$sizes[2]]) ? $extensions[$sizes[2]] : 'bmp';
			$mime_type = 'image/'.($extension === 'jpg' ? 'jpeg' : ($extension === 'bmp' ? 'x-ms-bmp' : $extension));
			$destName = 'avatar_'.$memID.'_'.time().'.'.$extension;
			$width = $sizes[0];
			$height = $sizes[1];
			$file_hash = empty($modSettings['custom_avatar_enabled']) ? getAttachmentFilename($destName, false, null, true) : '';

			// Remove previous attachments this member might have had.
			removeAttachments(array('id_member' => $memID));

			$smcFunc['db_insert']('',
				'{db_prefix}attachments',
				array(
					'id_member' => 'int', 'attachment_type' => 'int', 'filename' => 'string', 'file_hash' => 'string', 'fileext' => 'string', 'size' => 'int',
					'width' => 'int', 'height' => 'int', 'mime_type' => 'string', 'id_folder' => 'int',
				),
				array(
					$memID, 2, $destName, $file_hash, $extension, filesize($tmpFile),
					(int) $width, (int) $height, $mime_type, $id_folder,
				),
				array('id_attach')
			);

			$cur_profile['id_attach'] = $smcFunc['db_insert_id']('{db_prefix}attachments', 'id_attach');
			$cur_profile['filename'] = $destName;
			$cur_profile['attachment_type'] = 2;

			$destinationPath = $uploadDir.'/'.(empty($file_hash) ? $destName : $cur_profile['id_attach'].'_'.$file_hash);
			if (!rename($tmpFile, $destinationPath)) {
				removeAttachments(array('id_member' => $memID));
				fatal_lang_error('attach_timeout', 'critical');
			}
			chmod($uploadDir.'/'.$destinationPath, 0644);
		}
		$profile_vars['avatar'] = 'ppsgravatar';

		// Delete any temporary file.
		if (file_exists($uploadDir.'/avatar_tmp_'.$memID))
			@unlink($uploadDir.'/avatar_tmp_'.$memID);
	}
}

?>