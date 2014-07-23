<?php
if(!isset($_SESSION)){
	session_start();
}

if(!isset($_SESSION["filenames"])){
	$_SESSION["filenames"] = new stdClass();	
	$_SESSION["filenames"]->edit_1 = generateFileName();
	$_SESSION["filenames"]->edit_2 = generateFileName();
	$_SESSION["filenames"]->edit_3 = generateFileName();
	$_SESSION["filenames"]->edit_4 = generateFileName();
	$_SESSION["filenames"]->edit_5 = generateFileName();
	$_SESSION["filenames"]->static_1 = generateFileName();
	$_SESSION["filenames"]->static_2 = generateFileName();
	$_SESSION["filenames"]->static_3 = generateFileName();
}


//generate unique file name for photo on server
function generateFileName(){
	$assembledname = "";
	$chars  = "abcdefghijklmnopqrstuvwxyz1234567890";
	
	for($i=0;$i<20;$i++){
		$randomChar = rand(0,strlen($chars)-1);
		$assembledname .= substr($chars,$randomChar,1);
	}

	return $assembledname;
}

function uploadedImage($fileuploaded,$newfilename){
	
	//verot.net
	include('includes/class.upload.php');

	global $useuploadedfile;

	//reads file header data
	$exif = exif_read_data($fileuploaded["tmp_name"]);

	$filepath = "/uploads/";
	$processed_dir = getcwd() . $filepath;

	$handle = new Upload($fileuploaded["tmp_name"]);
	  if ($handle->uploaded) {
		  $handle->file_overwrite = true;
		  $handle->file_auto_rename = false;
		  $handle->image_convert = 'jpg';

		  	//if file is rotated 
		  if($exif){
		 	if(isset($exif['Orientation']) && $exif['Orientation']==6){
		  		$handle->image_rotate = 90;
			}
		  }

		  $handle->image_resize = true;
		  $handle->image_x = 600;
		  $handle->image_ratio_y = true;
		  $handle->file_new_name_body  = $_SESSION["filename"];
		  
		  $handle->process($processed_dir);
		  if ($handle->processed){
		  		//used for faceboook uploads
			  	//$_SESSION["uploadedimagefile"] =  "https://" . $_SERVER["HTTP_HOST"]  . "/" .  $filepath . $handle->file_dst_name;
		  		return $handle->file_dst_name;
		  } else {
			  	return "Error " . $handle->error;
		  }
		 $handle->clean();
	  }
}


function createComposedImage($filename_with_path,$device = 'desktop'){

	include('includes/class.upload.php');

	$destinationdir = getcwd() . "/composites/"; 
	$overlay_item = getcwd() . "/images/frame.png";

	//this url is returned to flash app
	$absolute_url_of_image = ("http://"  . $_SERVER["HTTP_HOST"]  . "/composites/");

	$handle = new upload($filename_with_path);
	  if ($handle->uploaded) {
			$handle->file_overwrite = true;
			$handle->file_auto_rename = false;

			if(preg_match("/phone/",$device)){
//				$top = (-116 + overlayAdjust($device,$template));
//				$bottom = (-189 - overlayAdjust($device,$template)); 
//				$handle->image_crop = ($top . 'px -206px ' . $bottom  . 'px -206px');
			}
			else{
				$handle->image_crop = ('-161px -62px -91px -68px');
			}
			
			$handle->image_watermark       = $overlay_item;
		    $handle->image_watermark_x     = 0;
			$handle->image_watermark_y     = 0;

			//They represent the amount cropped top, right, bottom and le
		  
			$handle->process($destinationdir);
				if ($handle->processed){
					//removes temporary file
					//error_log($filename_with_path);
					unlink($filename_with_path);
					$handle->clean();
			  		return ($absolute_url_of_image  . $handle->file_dst_name . "?date=" . time());
					//$handle->clean();
			  	} else {
				  	return $handle->error;
			  	}
	  }
}

function getShortUrl($url = null){
	//uses session variable ot creat eurl with file name

	$data = json_encode(array('longUrl' => $url));

	$user_agent = 'SUKI NINA/1.0 (http://deluxeluxury.com/)';

	$ch = curl_init();
	curl_setopt($ch,CURLOPT_USERAGENT, $user_agent);
	curl_setopt($ch, CURLOPT_URL, "https://www.googleapis.com/urlshortener/v1/url?key=APIKEY");
	curl_setopt($ch,CURLOPT_HTTPHEADER, array('Content-type: application/json'));
	curl_setopt($ch,CURLOPT_HTTP_VERSION,CURL_HTTP_VERSION_1_1);
	curl_setopt($ch,CURLOPT_POST, TRUE);
	curl_setopt($ch,CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER, true); //need this to suppress output to browser

	$result = curl_exec($ch);
	curl_close($ch);
	
	return json_decode($result)->id;

}// getShortUrl



?>