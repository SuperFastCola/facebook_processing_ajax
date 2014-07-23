<?php
ob_start();
session_start();
error_reporting(E_ALL);

define('FB_DOCUMENT_ROOT', dirname(realpath(__FILE__)) . "/"); // need to add trailing slash

define('MAIN_DIRECTORY', preg_replace("/includes/i","",dirname(realpath(__FILE__)))); // need to add trailing slash
define('UPLOAD_SUBDIRECTORY', "uploads/"); // need to add trailing slash
define('COMPOSED_SUBDIRECTORY', "composed_images/"); // need to add trailing slash

if(preg_match("/hhsecure\.com/",$_SERVER['HTTP_HOST'])){
	$image_placment_directory = "/";
}
else{
	$image_placment_directory = "/staging/";
}

define('UPLOAD_URL_BASE', "https://" . $_SERVER['HTTP_HOST'] . $image_placment_directory . UPLOAD_SUBDIRECTORY); // need to add trailing slash
define('COMPOSED_URL_BASE', "https://" . $_SERVER['HTTP_HOST'] . $image_placment_directory . COMPOSED_SUBDIRECTORY); // need to add trailing slash

require_once(MAIN_DIRECTORY . "config.php");
require_once(FB_DOCUMENT_ROOT . "image.functions_v2.php");

//verot.net class
require_once(FB_DOCUMENT_ROOT . "class.upload.php");

//this has to be declared in outermost scope
use Aws\Common\Aws;



function getUserIDSaveTokenToSession(){

	global $app_id,$app_secret;

	$myobject = new stdClass();
	$myobject->message=$_REQUEST['access_token'];
	
	$userinfourl = "https://graph.facebook.com/me/?access_token=";
    $user_profile = json_decode(curlget($userinfourl . $_REQUEST['access_token']));

    if(isset($user_profile->id)){

	    $_SESSION["user_id"] = $user_profile->id;

	    //submit access token to gte long lived token
	    if(!isset($_SESSION["long_lived_token"])){
	    	$get_long_lived_token = "https://graph.facebook.com/oauth/access_token?grant_type=fb_exchange_token&client_id=" . $app_id . "&client_secret=" . $app_secret  ."&fb_exchange_token=" . $_REQUEST['access_token'];
   		   	$_SESSION["access_token"] = processAccessTokenResult(curlget($get_long_lived_token));
	    	$_SESSION["long_lived_token"] = true;

	    }else{
	    	$_SESSION["access_token"] = $_REQUEST['access_token'];
   		    $myobject->message=$_SESSION["access_token"];
	
	    }

	}
	else{
		$myobject->message = "Login Error";
	}
	
	echo json_encode($myobject);
}

function getAccessToken(){
	echo $_SESSION["access_token"];
}


function createLoginURL(){	
	global $loginurl;
	return $loginurl;
}

//https://developers.facebook.com/blog/post/2011/05/13/how-to--handle-expired-access-tokens/
//using php get_file_contents causes 404 erros if access_token has expired 
function curl_get_file_contents($URL) {
    $c = curl_init();
    curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($c, CURLOPT_URL, $URL);
    $contents = curl_exec($c);
    $err  = curl_getinfo($c,CURLINFO_HTTP_CODE);
    curl_close($c);
    if ($contents) return $contents;
    else return FALSE;
  }
	

//You have to URL ENCODE Parenthesis and Pound Sign
$enc = array();
$enc["pl"] = urlencode("(");
$enc["pr"] = urlencode(")");
$enc["pound"] = urlencode("#");


//checks passed session value in POST
function checksession($tocheck){
	if(preg_match("/connected/i",$tocheck)){
		return true;
	}
	else{
		return false;
	}
}

function userAndToken(){
	global $app_id;

	if(isset($_SESSION["user_id"]) && isset($_SESSION["access_token"])){
		if(preg_match("/" .  $app_id . "/",$_SESSION["access_token"])){
			return false;
		}
		else{
			return true;
		}
	}
	else{
		return false;
	}
}

//checks to see if open graph api call is using expired token
function checkOAuthError($tocheck){
	//if((boolean) $tocheck->error->type=="OAuthException"){
	
	if(isset($tocheck->error->type)){
		return true;
	}
	else{
		return false;
	}
}


//gets all user photo albums.
function getAlbums($return_object=false){
	
	global $enc;

	unset($_SESSION["app_data"]);

	if(!userAndToken()){
		echo json_encode(array("error"=>createLoginURL()));
	}
	else{
		
		$fqlquery = '{"query1":"SELECT+cover_pid,aid,name,like_info,comment_info+FROM+album+WHERE+owner='. $_SESSION["user_id"] . '","query2":"SELECT+src,aid+FROM+photo+WHERE+pid+IN+' . $enc["pl"] . 'SELECT+cover_pid+FROM+' . $enc["pound"]  .'query1' .  	$enc["pr"] . '"}&access_token=' . $_SESSION["access_token"];
		$token_url = 'https://graph.facebook.com/fql?q=' . $fqlquery;	
		$response = curlget($token_url);
		
		//for testing
		$data = json_decode($response);
		//error_log(json_encode($data) . "\n\n");	
		
		if(checkOAuthError($data)){

			$login_array = array("error"=>createLoginURL());

			if(!$return_object){
				echo json_encode($login_array);
			}
			else{
				return $login_array;
			}
		}
		else{
			
			if(isset($data->data[0]->fql_result_set)){
				$albums = $data->data[0]->fql_result_set;
				$albumsPhotos = $data->data[1]->fql_result_set;
				$newAlbum = array();
										
				$g=0;
				$i=0;
			
				$currentpage = 0;
				$pagecounter = 0;
				$itemsonpage = 16;
				
				//error_log(json_encode($albums) . "\n\n");			
				//error_log(sizeof($albums) . "\n\n");

				while($i<sizeof($albumsPhotos)){
						//error_log(json_encode($albums[$g]));
						if($albums[$g]->aid == $albumsPhotos[$i]->aid){	
							$newAlbum[$currentpage][] = array($albums[$g]->name,$albumsPhotos[$i]->src,$albums[$g]->aid);
							$i++;
							
								if($pagecounter<$itemsonpage-1){
									$pagecounter++;
								}
								else{
									$currentpage++;
									$pagecounter = 0;
								}			
						}
						else if($albums[$g]->aid==0 || $albumsPhotos[$i]->aid==0){
							//update if album id is zeor
							$i++;
						}
			
						if($g<sizeof($albums)-1){
							$g++;
						}
						else{
							$g=0;
						}
						
				}
				
				//$newAlbum[$currentpage][] = $_SESSION;
				if(!$return_object){
					echo json_encode($newAlbum);	
				}
				else{
					return $data;
				}
				
	
			}// end if
			else{
				echo createLoginURL();
			}
		}//end else
	}// end if
	
}


function getMostPopularPhotos(){

	$albums = getAlbums(true);

	if(!isset($albums->data[0])){
			echo json_encode(array("error"=>createLoginURL()));
	}
	else{		

		$albums = getAlbums(true);
		$last_number = 0;
		$album_ids = array();

		$photos_to_use = array();
		$photo_pids = array();


		//gets photos albums with top likes
		foreach($albums->data[0]->fql_result_set as $a){

			if($a->like_info->like_count>$last_number  && !array_search($a->aid,$album_ids)){
				array_unshift($album_ids,$a->aid);
				$last_number = $a->like_info->like_count;
			}
			else if($a->like_info->like_count>0){
				$album_ids[] = $a->aid;
			}
		}

		//gets photos albums with top comment if sizeof($albums) less than 5 
		if(sizeof($album_ids)<5){
			
			$last_number = 0;

			foreach($albums->data[0]->fql_result_set as $a){
				if($a->comment_info->comment_count>$last_number && !array_search($a->aid,$album_ids)){
					array_unshift($album_ids,$a->aid);
					$last_number = $a->comment_info->comment_count;
				}
				else if($a->comment_info->comment_count>0){
					$album_ids[] = $a->aid;
				}
			}
		}

		//gets photos with top comments
		for($i=0;$i<sizeof($album_ids);$i++){
			$photos = getAlbumPhotos($album_ids[$i],true);

			$last_number = 0;

			foreach($photos->data[0]->fql_result_set as $p){

				if($p->comment_info->comment_count>0 && !array_search($p->pid,$photo_pids)){
					array_unshift($photos_to_use,array($p->src_big,$p->comment_info->comment_count,$p->pid));
					$photo_pids[] = $p->pid;
					$last_number = $p->comment_info->comment_count;
				}
			}
		}

		//gets photos with top like if sizeof($photos_to_use) less than 5 albums
		if(sizeof($photos_to_use)<5){
			for($i=0;$i<sizeof($album_ids);$i++){
				$photos = getAlbumPhotos($album_ids[$i],true);
				$last_number = 0;

				foreach($photos->data[0]->fql_result_set as $p){

					if($p->like_info->like_count>$last_number && !array_search($p->pid,$photo_pids)){
						array_unshift($photos_to_use,array($p->src_big,$p->like_info->like_count,$p->pid));
						$photo_pids[] = $p->pid;
						$last_number = $p->like_info->like_count;
					}
				}
			}
		}

		//reduces array to 5 indexes
		$photos_to_use = array_slice($photos_to_use, 0,5);

		$index = 0;
		$object_id = 1;
		$initial_photos = new stdClass();

		//place top 5 photos on server
		foreach($_SESSION["filenames"] as $name){
			$id = "edit_" . $object_id;

			if(isset($initial_photos->{$id})){
				$initial_photos->{$id} = new stdClass();
				$initial_photos->{$id}->id = $id;
				$initial_photos->{$id}->src = placeFacebookPhotoOnServer($photos_to_use[$index][0],$id,true);
			}
			
			$object_id++;
			$index++;
		}

		echo json_encode($initial_photos);
	}
	
}


function getAlbumPhotos($albumid,$return_object = false){
	global $enc;
	
	if(!$albumid || !userAndToken()){
		echo json_encode(array("error"=>createLoginURL()));
	}
	else{
		
		$fqlquery = '{"query1":"SELECT+created,src,comment_info,like_info,src_big,pid+FROM+photo+WHERE+aid=\''.  $albumid .  '\'"}&access_token=' . $_SESSION['access_token']; 
		$token_url = 'https://graph.facebook.com/fql?q=' . $fqlquery;
		
		$response = curlget($token_url);
		$data = json_decode($response);		
					
		if(checkOAuthError($data)){
			echo json_encode(array("error"=>createLoginURL()));
		}
		else{					
				
			if(isset($data->data[0]->fql_result_set)){
								
				$currentpage = 0;
				$pagecounter = 0;
				$itemsonpage = 16;
				
				foreach($data->data[0]->fql_result_set as $photo){
					
					$albumPhotos[$currentpage][] = array(date("M-d-Y", $photo->created),$photo->src ,$photo->pid);
					
					if($pagecounter<$itemsonpage-1){
						$pagecounter++;
					}
					else{
						$currentpage++;
						$pagecounter = 0;
					}		
					
				}	
				
				if(!$return_object){
					echo json_encode($albumPhotos);
				}
				else{
					return $data;
				}
	
			}//end if
			else{
				echo createLoginURL();
			}//end esle
		}//end esle
	}//end else
	
}

function getPhotos($photoid,$title){
	
	global $enc;
	
	if(!$photoid || !$title || !userAndToken()){
		$appdata = '&app_data=' .  urlencode('{"requestperms":"true"}');
		echo createLoginURL();
	}
	else{
		$fqlquery = '{"query1":"SELECT+created,images,src_big,comment_info,like_info,pid+FROM+photo+WHERE+pid=\''.  $photoid .  '\'"}&access_token=' . $_SESSION['access_token']; 
		$token_url = 'https://graph.facebook.com/fql?q=' . $fqlquery;
				
		$response = curl_get_file_contents($token_url);	
		$data = json_decode($response);
			
		if(checkOAuthError($data)){
			echo createLoginURL();
		}
		else{					
		
			if(isset($data->data[0]->fql_result_set[0])){
				$photo = $data->data[0]->fql_result_set[0];
				
				echo '<div id="selectedphoto">';

				//echo '<span>'. (($title)?$title:date("M-d-Y", $photo->created)). '</span>';
				echo '<div class="selected_coverholder">';
				echo '<img src="'. $photo->images[0]->source  .  '" id="' . $photo->pid  . '"/>';
				echo '</div>';

				echo '<div id="selectednav">';
				echo '<a id="butn_usephoto" class="butn">Use Photo</a>';
				echo '<a id="butn_cancelphoto" class="butn">Cancel</a>';
				echo '</div>';
				
		
				
				echo '</div>';
			}// end if
			else{
				echo createLoginURL();
			}// end else
		}//end else
	}
}


function placeFacebookPhotoOnServer($fileurl,$objid,$return_object = false){
	//http://answers.yahoo.com/question/index?qid=20080120154409AAjlZKu
	
	//for testing
	//$fileurl .= "?oh=36a8371007d113ffc38fb3ca1eac59cf&oe=532CDE05";

	$tmp = explode(".",$fileurl);
	$filext = $tmp[sizeof($tmp)-1];

	//some facebook files have parameters
	//so strip them like Adam Ant
	$filext = explode("?",$filext);
	$filext = $filext[0];
	
	$ch = curl_init();
	$timeout = 0;
	curl_setopt ($ch, CURLOPT_URL, $fileurl);
	curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
	
	// Getting binary data
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
	$filecontents = curl_exec($ch);
	curl_close($ch);
	
	//create filename with facebook userid and current working directory with temp directory
	//if(!isset($_SESSION["filename"])){
		//$_SESSION["filename"] = generateFileName();

	$filename = $_SESSION["filenames"]->{$objid};

	//}
		
	$filelocation_local = MAIN_DIRECTORY . UPLOAD_SUBDIRECTORY . $filename . "." . $filext;
	$filelocation_url = UPLOAD_URL_BASE .  $filename . "." . $filext;

	file_put_contents($filelocation_local, $filecontents);
	chmod($filelocation_local, 0775);

	if(!$return_object){
		echo $filelocation_url;
	}
	else{
		return $filelocation_url;	
	}
}


//THERE IS A TIME OUT ISSUES WITH THIS. Also FILE NAME NEED TO OVERWRITE
function rotatePhoto($filename,$counterclockwise){
	
	$filelocation_local = MAIN_DIRECTORY . UPLOAD_SUBDIRECTORY . $filename;
	$filelocation_url = UPLOAD_URL_BASE . $filename;

	$handle = new upload($filelocation_local);
	$handle->file_overwrite = true;
	$handle->file_auto_rename = false;
	
	if($handle->uploaded) {

		if($counterclockwise=="true"){
			$handle->image_rotate = 270;
		}
		else{
			$handle->image_rotate = 90;	
		}
	  	
		$handle->process(MAIN_DIRECTORY . UPLOAD_SUBDIRECTORY);

		if ($handle){
			 echo $filelocation_url . '?t=' .  time();
		} else {
			echo 'error : ' . $handle->error;
		}
	}
}

function debug_to_console( $data ) {

    if ( is_array( $data ) )
        $output = "<script>console.log( 'Debug Objects: " . implode( ',', $data) . "' );</script>";
    else
        $output = "<script>console.log( 'Debug Objects: " . $data . "' );</script>";

    echo $output;
}

function cropImage($filename,$img_w,$top,$right,$bottom,$left,$alternate,$device){
	

	$filelocation_local = MAIN_DIRECTORY . UPLOAD_SUBDIRECTORY . $filename;
	//debug_to_console($filelocation_local);
	$filelocation_url = UPLOAD_URL_BASE .  $filename;

	$handle = new upload($filelocation_local);
	  if ($handle->uploaded) {
			$handle->file_overwrite = true;
			$handle->file_auto_rename = false;
			//$handle->file_new_name_body   =  $imagecropped;
			$handle->image_resize         = true;
			$handle->image_x              = $img_w;
			$handle->image_ratio_y = true;		  
			$handle->jpeg_quality = 100;

			//remove for live version
			//$handle->file_new_name_body = "tester";

			//the values are the amount of pixels to crop off on each side
			// NOT coordinates
			$handle->image_crop = array($top,$right,$bottom,$left);
			$handle->image_background_color = '#ffffff';
		  
		  $handle->process(MAIN_DIRECTORY . UPLOAD_SUBDIRECTORY);
		  if ($handle->processed){
		  		if(!$alternate){
			   		$pictureobject = '"user":"' . $filelocation_url . '?t=' .  time() . '"';
					echo "{" . $pictureobject . "}";
				}
		  } else {
			  	echo 'error : ' . $handle->error;
		  }
		  
		  //unlink($imagelocal);
		 //$handle->clean();
	  }
}

function cropImageMultiple($cropdata,$title_text,$background_color = "#81a99e"){

	$output = array();

	$photo_coordinates = array();
	$photo_coordinates[] = array(10,11); //edit 1
	$photo_coordinates[] = array(248,101); //edit 2
	$photo_coordinates[] = array(335,11); //edit 3
	$photo_coordinates[] = array(539,11); //edit 4
	$photo_coordinates[] = array(627,11); //edit 5

	$static_coordinates = array();
	$static_coordinates[] = array(187,13); //static 1 = b1
	$static_coordinates[] = array(187,119); //static 2 = c1
	$static_coordinates[] = array(633,119); //static 3 = b2

	//for static mosaic imagery for edit_1 thru edit_5
	$mosaic_coordinates_editable = array();
	$mosaic_coordinates_editable[] = array(12,13); //edit 1 = a1
	$mosaic_coordinates_editable[] = array(291,119); //edit 2 = c2
	$mosaic_coordinates_editable[] = array(394,13); //edit 3 = a2
	$mosaic_coordinates_editable[] = array(633,13); //edit 4 = c3
	$mosaic_coordinates_editable[] = array(736,13); //edit 5 = c4

	$a_size = array(234,207);
	$b_size = array(202,101);
	$c_size = array(98,101);

	$col1 = 29;
	$col2 = 133;
	$col3 = 237;
	$col4 = 269;
	$col5 = 373;

	$row1 = 29;
	$row2 = 135;
	$row3 = 242;
	$row4 = 349;

	$share_image_coordinates = new stdClass();
	$share_image_coordinates->edit_1 = array("size"=>$a_size,"position"=>array($col1,$row1)); //edit 1 = a1
	$share_image_coordinates->edit_2 = array("size"=>$c_size,"position"=>array($col2,$row4)); //edit 2 = c2
	$share_image_coordinates->edit_3 = array("size"=>$a_size,"position"=>array($col3,$row3)); //edit 3 = a2
	$share_image_coordinates->edit_4 = array("size"=>$c_size,"position"=>array($col4,$row1)); //edit 4 = c3
	$share_image_coordinates->edit_5 = array("size"=>$c_size,"position"=>array($col5,$row1)); //edit 5 = c4
	$share_image_coordinates->static_1 = array("size"=>$b_size,"position"=>array($col1,$row3)); //static 1 = b1
	$share_image_coordinates->static_2 = array("size"=>$c_size,"position"=>array($col1,$row4)); //static 2 = c1
	$share_image_coordinates->static_3 = array("size"=>$b_size,"position"=>array($col4,$row2)); //static 3 = b2

	foreach($cropdata as $i){

		$filelocation_local = MAIN_DIRECTORY . UPLOAD_SUBDIRECTORY . trim($i["imagename"]);
		//debug_to_console($filelocation_local);
		$filelocation_url = UPLOAD_URL_BASE;

		if(isset($i["local_src"]) && !preg_match("/[0-9a-zA-Z]{20}/i",$i["imagename"])){
			
			if(preg_match("/edit/i",$i["object_id"])){
				$indice = (integer) preg_replace("/edit_/i","",$i["object_id"]);
				$coordinates = $mosaic_coordinates_editable[$indice - 1];
			}
			else{
				$indice = (integer) preg_replace("/static_/i","",$i["object_id"]);
				$coordinates = $static_coordinates[$indice - 1];
			}
			
			//if mosaic image and over facebook photo
			if(preg_match("/edit_1/",$i["object_id"])){
				$over_facebook_photo = true;
			}
			else{
				$over_facebook_photo = false;
			}

			$photo_to_place[] = array(MAIN_DIRECTORY . $i["local_src"],$coordinates,true,$over_facebook_photo,$i["object_id"]);
		}
		else{

			//$handle = new upload($filelocation_local);
			
			$filename_key = preg_replace("/\.jpg/i","",$i["imagename"]);

			$crop_source_size = list($width, $height) = getimagesize($filelocation_local);
			$crop_source = imagecreatefromjpeg($filelocation_local);

			$resized_for_crop = imagecreatetruecolor($i["imageprops"]["width"],$i["imageprops"]["height"]);
			imagecopyresampled($resized_for_crop,$crop_source,0,0,0,0,$i["imageprops"]["width"],$i["imageprops"]["height"],$crop_source_size[0],$crop_source_size[1]);

			$resized_file = MAIN_DIRECTORY . UPLOAD_SUBDIRECTORY . $filename_key . "_resized.png";

			$crop_destination_width = $i["imageprops"]["width"] - ($i["cropamounts"]["left"] + $i["cropamounts"]["right"]);
			$crop_destination_height = $i["imageprops"]["height"] - ($i["cropamounts"]["top"] + $i["cropamounts"]["bottom"]); 

			$crop_destination = imagecreatetruecolor($crop_destination_width,$crop_destination_height);

			imagesavealpha($crop_destination, true);
    		$trans_colour = imagecolorallocatealpha($crop_destination, 255, 0, 0, 127);
   			imagefill($crop_destination, 0, 0, $trans_colour);

			//imagecopyresampled($crop_destination,$resized_for_crop,0,0,$i["cropamounts"]["top"],$i["cropamounts"]["left"],$crop_destination_width,$crop_destination_height,$i["imageprops"]["width"],$i["imageprops"]["height"]);

			$pos_left = $i["cropamounts"]["left"];
			$pos_top = $i["cropamounts"]["top"];

			$dest_x = ($pos_left<0)?$pos_left*-1:0;
			$dest_y = ($pos_top<0)?$pos_top*-1:0;
			$source_x = ($pos_left<0)?0:$pos_left;
			$source_y = ($pos_top<0)?0:$pos_top;

			imagecopy($crop_destination,$resized_for_crop,$dest_x,$dest_y,$source_x,$source_y,$i["imageprops"]["width"],$i["imageprops"]["height"]);

			$destinationfile = MAIN_DIRECTORY . UPLOAD_SUBDIRECTORY . $filename_key . "_crop.png";

			deleteImageFile($destinationfile);

		   	imagepng($crop_destination, $destinationfile,9);

			imagedestroy($crop_source);
		   	imagedestroy($crop_destination);
		   	imagedestroy($resized_for_crop);

		   	//$output[] = '"user":"' . $filelocation_url . $handle->file_dst_name . '?t=' .  time() . '"';
			$indice = (integer) preg_replace("/edit_/i","",$i["object_id"]);
			$coordinates = $photo_coordinates[$indice - 1];
			$photo_to_place[] = array($destinationfile,$coordinates,false,false,$i["object_id"]);


	/*	  if($handle->uploaded){
				//$handle->file_overwrite = false;
				//$handle->file_auto_rename = false;
				$handle->image_convert = "png";
				$handle->file_new_name_body   =  ($filename_key . "_crop");
				$handle->image_resize         = true;
				$handle->image_x              = $i["imageprops"]["width"];
				$handle->image_ratio_y = true;
				$handle->png_compression  = 1;		  
				//$handle->image_background_color = $background_color;

				//remove for live version
				//$handle->file_new_name_body = "tester";
				//the values are the amount of pixels to crop off on each side
				// NOT coordinates
				$handle->image_crop = array($i["cropamounts"]["top"],$i["cropamounts"]["right"],$i["cropamounts"]["bottom"],$i["cropamounts"]["left"]);
			  
			  $handle->process(MAIN_DIRECTORY . UPLOAD_SUBDIRECTORY);
			  
			  if ($handle->processed){
			  		//if(!isset($alternate)){
				   		$output[] = '"user":"' . $filelocation_url . $handle->file_dst_name . '?t=' .  time() . '"';
			   			$indice = (integer) preg_replace("/edit_/i","",$i["object_id"]);
			   			$coordinates = $photo_coordinates[$indice - 1];
			   			$photo_to_place[] = array( MAIN_DIRECTORY . UPLOAD_SUBDIRECTORY . $handle->file_dst_name,$coordinates,false,false,$i["object_id"]);
						//echo "{" . $pictureobject . "}";
					//}
			  } else {
				  	error_log('error : ' . $handle->error);
			  }
			  
			  //unlink($imagelocal);
			 //$handle->clean();
		  }*/
		}
	}

	//error_log(json_encode($photo_to_place));

	// The text to draw
	$font_size = 21;
	$font_size_share = 16;

	// Replace path by your own font path
	$font = MAIN_DIRECTORY . "fonts/FYISansBeta-Bold.ttf";
	$title_box_width = imageftbbox($font_size, 0, $font, $title_text);

	if($title_box_width[0] + $title_box_width[2] > 460){
		$font_size = 16;
		$reduce_font = true;
		$font_size_share = 13;
	}

	$backimage = MAIN_DIRECTORY . "images/fyi_background_gradient.png";

	$background_gradient = imagecreatefrompng($backimage);

	//create new final image and fill with a color
	$finalimage = imagecreatetruecolor(849,313);
	$finalimage_share = imagecreatetruecolor(500,500);

	$stripped_color_hex  = preg_replace("/#/i","",$background_color);

	$hexes = array();
	$hexes[] = "0x" . substr($stripped_color_hex, 0, 2);
	$hexes[] = "0x" . substr($stripped_color_hex, 2, 2);
	$hexes[] = "0x" . substr($stripped_color_hex, 4, 2);

	$final_background_color = imagecolorallocate($finalimage, $hexes[0], $hexes[1], $hexes[2]);
	imagefill($finalimage, 0, 0, $final_background_color);
	imagefill($finalimage_share, 0, 0, $final_background_color);
	
	//creates photo placement montage image base at reduced size
	$photo_placements = imagecreatetruecolor(724,268);
	//saves alpha of said image
    imagesavealpha($photo_placements, true);

    $trans_colour = imagecolorallocatealpha($photo_placements, 255, 0, 0, 127);
    imagefill($photo_placements, 0, 0, $trans_colour);

	$back_gradient_image_size = list($width, $height) = getimagesize($backimage);
	$final_image_size = list($width, $height) = getimagesize($backimage);
	
    imagecopyresampled($finalimage_share, $background_gradient, 0, 0, 0, 0, 500, 500, $back_gradient_image_size[0], $back_gradient_image_size[1]);


	foreach($photo_to_place as $fi){

		//if no place after
		if(!$fi[2]){
			$image_path = $fi[0];
			//$temp_image = imagecreatefromjpeg($image_path);
			$temp_image = imagecreatefrompng($image_path);

			$temp_image_size = list($width, $height) = getimagesize($image_path);
			imagecopy($photo_placements,$temp_image,$fi[1][0],$fi[1][1], 0, 0,$temp_image_size[0],$temp_image_size[1]);

			//copy to share image
			$f_pos = $share_image_coordinates->{$fi[4]}["position"];
			$f_dim = $share_image_coordinates->{$fi[4]}["size"];
			imagecopyresampled($finalimage_share,$temp_image,$f_pos[0],$f_pos[1], 0, 0,$f_dim[0],$f_dim[1],$temp_image_size[0],$temp_image_size[1]);		

			imagedestroy($temp_image);
		}
	}

	//add gradient over color background
	imagecopy($finalimage, $background_gradient, 0, 0, 0, 0, $back_gradient_image_size[0], $back_gradient_image_size[1]);

	//resizes photo placement to fit in final image space
	imagecopyresampled($finalimage,$photo_placements,0,0,0,0,$final_image_size[0],$final_image_size[1],724,268);

	foreach($photo_to_place as $fi){

		//if no place after
		if($fi[2]){

			$image_path = $fi[0];
			$temp_image = imagecreatefromjpeg($image_path);
			$temp_image_size = list($width, $height) = getimagesize($image_path);

			//if image is A1 - shrink
			if($fi[3]){

				$a1_width = 170;
				$a1_height = 150;
				$a1_resized = imagecreatetruecolor($a1_width,$a1_height);
				imagecopyresampled($a1_resized,$temp_image,0,0,0,0,$a1_width,$a1_height,$temp_image_size[0],$temp_image_size[1]);
				imagecopy($finalimage,$a1_resized,$fi[1][0],$fi[1][1],0,0,$a1_width,$a1_height);		

				imagedestroy($a1_resized);
			}
			else{
				imagecopy($finalimage,$temp_image,$fi[1][0],$fi[1][1], 0, 0,$temp_image_size[0],$temp_image_size[1]);		
			}

			//copy to share image
			$f_pos = $share_image_coordinates->{$fi[4]}["position"];
			$f_dim = $share_image_coordinates->{$fi[4]}["size"];

			imagecopyresampled($finalimage_share,$temp_image,$f_pos[0],$f_pos[1], 0, 0,$f_dim[0],$f_dim[1],$temp_image_size[0],$temp_image_size[1]);	

			imagedestroy($temp_image);
			
		}
	}

	//add mvpd logo
	$mvpd_logo_source = MAIN_DIRECTORY . "images/fyi_mvpd_logo.png";
	$mvpd_logo_overlay = imagecreatefrompng($mvpd_logo_source);
	$temp_image_size = list($width, $height) = getimagesize($mvpd_logo_source);
	imagecopy($finalimage,$mvpd_logo_overlay,690,230, 0, 0,$temp_image_size[0],$temp_image_size[1]);
	imagecopyresampled($finalimage_share,$mvpd_logo_overlay,384,460, 0, 0,106,28,$temp_image_size[0],$temp_image_size[1]);

	//$finalimage  = imagecreatetruecolor(403, 403);

	$white = imagecolorallocate($finalimage, 255, 255, 255);

	// write teams
	$text_left = 206;
	$text_top = 255;

	$text_left_share = 13;
	$text_top_share = 478;

	if(isset($reduce_font)){
		$text_top -= 2;
	}

	imagettftext($finalimage, $font_size, 0, $text_left, $text_top, $white, $font, $title_text);
	imagettftext($finalimage_share, $font_size_share, 0, $text_left_share, $text_top_share, $white, $font, $title_text);

	// Using imagepng() results in clearer text compared with imagejpeg()
	//imagejpeg($finalimage);

	if(!isset($_SESSION["composed_filename"])){
		$filename = generateFileName();
		$_SESSION["composed_filename"] = $filename . ".jpg";
		$_SESSION["composed_filename_share"] = $filename . "_share.jpg";
	 }
	 else{
	 	deletePreviousComposedImage($_SESSION["composed_filename"]);
	 	deletePreviousComposedImage($_SESSION["composed_filename_share"]);

	 	$filename = generateFileName();
	 	$_SESSION["composed_filename"] = $filename . ".jpg";
	 	$_SESSION["composed_filename_share"] = $filename . "_share.jpg";
	 }

	$final_file_name = $_SESSION["composed_filename"];
	$final_file_name_share = $_SESSION["composed_filename_share"];

	$final_file_destination = MAIN_DIRECTORY . COMPOSED_SUBDIRECTORY . $final_file_name;
	$final_file_destination_share = MAIN_DIRECTORY . COMPOSED_SUBDIRECTORY . $final_file_name_share;

	imagejpeg($finalimage,$final_file_destination,100);
	imagejpeg($finalimage_share,$final_file_destination_share,100);

	imagedestroy($finalimage);
	imagedestroy($finalimage_share);
	imagedestroy($background_gradient);
	imagedestroy($photo_placements);
	imagedestroy($mvpd_logo_overlay);

	chmod($final_file_destination, 0774);
	chmod($final_file_destination_share, 0774);

	$image = new stdClass();
	$image->cover = postImageToFacebookProfile($final_file_name);
	$image->poster = postImageToFacebookProfile($final_file_name_share);

	echo json_encode($image);
}

function deleteImageFile($filename_with_location){
		if(file_exists($filename_with_location)){
			unlink($filename_with_location);
		}
}

function deletePreviousComposedImage($filename){
		$photo_file = MAIN_DIRECTORY . COMPOSED_SUBDIRECTORY . $filename;

		if(file_exists($photo_file)){
			unlink($photo_file);
		}
}

function deleteUpoadedPhotos(){
	foreach($_SESSION["filenames"] as $file){

		$photo_file = MAIN_DIRECTORY . UPLOAD_SUBDIRECTORY . $file . ".jpg";
		if(file_exists($photo_file)){
			unlink($photo_file);

			$cropped_file = MAIN_DIRECTORY . UPLOAD_SUBDIRECTORY . $file . "_crop.png";
			if(file_exists($cropped_file)){
				unlink($cropped_file);
			}
		}
	}
}

function postImageToFacebookProfile($photo){

		$phototopublish = MAIN_DIRECTORY . COMPOSED_SUBDIRECTORY . $photo;

		if(preg_match("/_share/i",$photo)){
			$upload_file_name = $_SESSION["composed_filename_share"];
			$sharekey = preg_replace("/_share\.jpg/i","",$_SESSION["composed_filename_share"]);
		}
		else{
			$upload_file_name = $_SESSION["composed_filename_share"];
			$sharekey = preg_replace("/\.jpg/i","",$_SESSION["composed_filename"]);	
		}
		

		if(isset($_SESSION["user_id"]) && preg_match("/\d+/",$_SESSION["user_id"])){
			$phototopublishsurl = "https://graph.facebook.com/" . $_SESSION["user_id"]  . "/photos?access_token=" . $_SESSION["access_token"];

			//set a post fields array for publishing to facebook.

			if(version_compare(PHP_VERSION, '5.5') >= 0) {
				$postfields = array(
				    "access_token" => $_SESSION["access_token"],
				    "message" => "Photo Test",
				    "image"=> new CurlFile($phototopublish,'image/jpeg',$upload_file_name)
				);
			}
			else{
				$postfields = array(
				    "access_token" => $_SESSION["access_token"],
				    "message" => "Photo Test",
				    "image"=> "@" . $phototopublish
				);
			}

			$phototopublishid = curlpost($phototopublishsurl,$postfields);
			$phototopublishid = json_decode($phototopublishid);
		}

		$final_object = new stdClass();	

		if(isset($phototopublishid->post_id)){
			$final_object->post_id = $phototopublishid->post_id;
			$final_object->id = $phototopublishid->id;
		}

//		$final_object->composed_image = COMPOSED_URL_BASE . $photo . '?t=' .  time();

		//uploads to Amazon and retrieves
		$result = uploadObjectToAmazon($photo,$phototopublish);
		$final_object->composed_image = $result["ObjectURL"];
		$final_object->sharekey = $sharekey;
		
		deletePreviousComposedImage($photo);
		//deleteUpoadedPhotos();

		return $final_object;
}


function uploadObjectToAmazon($key_filename,$source_file){

	require_once(MAIN_DIRECTORY . 'vendor/autoload.php');

	global $aws_credentials;

	$aws = Aws::factory(FB_DOCUMENT_ROOT . 'credentials.php');

	$client = $aws->get('S3');

	$result = $client->putObject(array(
		'ACL' => 'public-read',
	    'Bucket'     => "assets.somebucket.com/composed_images",
	    'Key'        => $key_filename,
	    'SourceFile' => $source_file
	));

	return $result;
}



function parse_signed_request($sr) {
  list($encoded_sig, $payload) = explode('.', $sr, 2); 
  // decode the data
  $sig = base64_url_decode($encoded_sig);
  $data = json_decode(base64_url_decode($payload), true);
  echo json_encode($data);
}
//https://developers.facebook.com/docs/authentication/signed_request/
function base64_url_decode($input) {
  return base64_decode(strtr($input, '-_', '+/'));
}


function check($val){
	return (isset($val))?$val:false;
}


if(isset($_POST["action"])){

	switch($_POST["action"]){

		case "get_user_info":
			getUserIDSaveTokenToSession();
		break;

		case "get_most_popular_photos":
			getMostPopularPhotos();
		break;

		case "get_ac_token":
			getAccessToken();
		break;

		case "parse_sr":
			parse_signed_request($_POST["signed_request"]);
		break;

		case "albums":
			getAlbums();
		break;
		
		case "albumsphotos":
			getAlbumPhotos($_POST["aid"]);
		break;
		
		case "getphoto":
			getPhotos(check($_POST["pid"]),check($_POST["title"]));
		break;

		case "sendphoto":
			placeFacebookPhotoOnServer($_POST["src"],$_POST["object_id"]);
		break;
		
		case "rotatephoto":
			rotatePhoto($_POST["src"],check($_POST["counter"]));
		break;
		
		case "cropimage":
			//cropImage($_POST["cropimage"],$_POST["image_w"],$_POST["top"],$_POST["right"],$_POST["bottom"],$_POST["left"], ((isset($_POST["alternate"]))?true:false),((isset($_POST["device"]))?$_POST["device"]:"desktop"));
			cropImageMultiple($_POST["crop_info"],$_POST["title_text"],$_POST["background_color"]);
		break;
		
		// case "saveimage":
		// 	saveImageToServer($_POST["contents"],$_POST["template"],$_POST["smalldevice"]);
		// break;
		
		// case "saveToFacebook":
		// 	uploadToFacebook();
		// break;
		
	}
}

?>
