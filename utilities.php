<?php

//----------------------------------------------------------------------------------------
function get($url, $format_type = '')
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HEADER, 1);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);	
	
	$headers = array();
	
	if ($format_type != '')
	{
		$headers[] = "Accept: " . $format_type;
		
		if ($format_type == 'text/html')
		{
			// play nice
			$headers[] = "Accept-Language: en-gb";
			$headers[] = "User-agent: Mozilla/5.0 (iPad; U; CPU OS 3_2_1 like Mac OS X; en-us) AppleWebKit/531.21.10 (KHTML, like Gecko) Mobile/7B405";
			
			// Cookies 
			curl_setopt($ch, CURLOPT_COOKIEJAR, sys_get_temp_dir() . '/cookies.txt');
			curl_setopt($ch, CURLOPT_COOKIEFILE, sys_get_temp_dir() . '/cookies.txt');	
		}
	}
	
	if (count($headers) > 0)
	{
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	}
	
	$response = curl_exec($ch);
	if($response == FALSE) 
	{
		$errorText = curl_error($ch);
		curl_close($ch);
		die($errorText);
	}
	
	$info = curl_getinfo($ch);
	$http_code = $info['http_code'];
	
	$header = substr($response, 0, $info['header_size']);
	//echo $header;
	
	$content = substr($response, $info['header_size']);
	
	curl_close($ch);
	
	return $content;
}

//----------------------------------------------------------------------------------------
function post($url, $data = '', $content_type = 'application/json; charset=utf-8')
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
	
	// data needs to be a string
	if ($data != '')
	{
		if (gettype($data) != 'string')
		{
			$data = json_encode($data);
		}	
	}	
	
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data);  
	
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
	
	
	$headers = array();
	
	if ($content_type != '')
	{
		$headers[] = "Content-type: " . $content_type;
	}
	
	if (count($headers) > 0)
	{
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	}
	
	$response = curl_exec($ch);
	if($response == FALSE) 
	{
		$errorText = curl_error($ch);
		curl_close($ch);
		die($errorText);
	}
	
	$info = curl_getinfo($ch);
	$http_code = $info['http_code'];
		
	curl_close($ch);
	
	return $response;
}

?>
