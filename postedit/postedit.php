<?php
/*
	replacement for posteditd.pl to handle secure sites
*/
global $progpath;
global $hosts;
global $cgroup;
global $chost;
global $argv;
global $mtimes;
ini_set("allow_url_fopen",1);
$mtimes=array();
global $mds;
$mds=array();
$progpath=dirname(__FILE__);
include_once("$progpath/../php/common.php");
getHosts();
$groups=getGroups();
if(isset($argv[1])){
	if(isset($hosts[$argv[1]])){$chost=$argv[1];}
}
if(!isset($chost)){
	if(!isset($cgroup)){$cgroup=selectGroup();}
	$chost=selectHost();
}
// acquire an exclusive lock
$lock=preg_replace('/[^a-z0-9]+/i','',$chost);
$lockfile="{$progpath}/{$lock}_lock.txt";
if(!is_file($lockfile)){
	file_put_contents($lockfile,time());
}
echo "Obtaining the Lock: {$lockfile}".PHP_EOL;
global $lockhandle;
$lockhandle = fopen($lockfile, "r+");

$count = 0;
$timeout_secs = 2; //number of seconds of timeout
$got_lock = true;
while (!flock($lockhandle, LOCK_EX | LOCK_NB, $wouldblock)) {
    if ($wouldblock && $count++ < $timeout_secs) {
        sleep(1);
    } else {
        $got_lock = false;
        break;
    }
}
if (!$got_lock) {
	echo "{$chost} is Already locked".PHP_EOL;
	exit;
}


//get the files
$afolder=writeFiles();
echo PHP_EOL."Listening to file in {$afolder} for changes...".PHP_EOL;
while(1){
	sleep(1);
	foreach($mtimes as $afile=>$mtime){
		$cmtime=filemtime($afile);
    	if($cmtime != $mtime){
	        //file Changed
        	$mtimes[$afile]=filemtime($afile);
        	fileChanged($afile);
		}
	}
}
exit;
function shutdown(){
	global $lockhandle;
	echo "Releasing the Lock".PHP_EOL;
	// release the lock
	flock($lockhandle, LOCK_UN);
	fclose($lockhandle);
}

function writeFiles(){
	global $hosts;
	global $chost;
	global $progpath;
	global $mtimes;
	$tables=isset($hosts[$chost]['tables'])?$hosts[$chost]['tables']:'_pages,_templates,_models';
	$postopts=array(
		'apikey'	=>$hosts[$chost]['apikey'],
		'username'	=>$hosts[$chost]['username'],
		'_noguid'	=>1,
		'postedittables'=>$tables,
		'apimethod'	=>"posteditxml",
		'encoding'	=>"base64",
		'-ssl'=>1
	);
	$url=buildHostUrl();
	echo "Calling {$url}...".PHP_EOL;
	$post=postURL($url,$postopts);
	if(isset($post['error']) && strlen($post['error'])){
    	echo PHP_EOL."!!ERROR!! - {$post['error']}".PHP_EOL.PHP_EOL;
    	exit;
	}
	//check for login form
	if(preg_match('/\"\_login\"/is',$post['body'])){
    	echo PHP_EOL."!!ERROR!! - INVALID LOGIN CREDENTIALS".PHP_EOL.PHP_EOL;
    	exit;
	}
	file_put_contents('postedit_pages.result',$post['body']);
	$xml = simplexml_load_string($post['body'],'SimpleXMLElement',LIBXML_NOCDATA | LIBXML_PARSEHUGE );
	$xml=(array)$xml;
	if(isset($post['curl_info']['http_code']) && $post['curl_info']['http_code'] != 200){
    	echo " - {$post['curl_info']['http_code']} error retrieving".PHP_EOL;
    	echo "{$post['body']}".PHP_EOL;
    	exit;
	}
	elseif(isset($xml['fatal_error'])){
    	echo " - Fatal error retrieving files".PHP_EOL;
    	echo "{$xml['fatal_error']}".PHP_EOL;
    	exit;
	}


	$folder=isset($hosts[$chost]['alias'])?$hosts[$chost]['alias']:$hosts[$chost]['name'];
	$afolder="{$progpath}/postEditFiles/{$folder}";
	if(is_dir($afolder)){cleanDir($afolder);}
	else{
		mkdir($afolder,0777,true);
	}
	foreach($xml['WASQL_RECORD'] as $rec){
		$rec=(array)$rec;
		$info=$rec['@attributes'];
		//echo printValue($info);exit;
		unset($rec['@attributes']);
		foreach($rec as $name=>$content){
	    	if(!strlen(trim($content))){continue;}
	    	$path="{$afolder}/{$info['table']}";
	    	if(!is_dir($path)){
				mkdir($path,0777,true);
			}
	    	//determine extension
	    	$parts=preg_split('/\_/',$name);
	    	$field=array_pop($parts);
	    	switch(strtolower($field)){
	        	case 'js':$ext='js';break;
	        	case 'css':$ext='css';break;
	        	case 'controller':
				case 'functions':
					$ext='php';
				break;
	        	default:
	        		$ext='html';
	        	break;
			}
	    	$afile="{$path}/{$info['name']}.{$info['table']}.{$field}.{$info['_id']}.{$ext}";
	    	//echo "{$afile}".PHP_EOL;
	    	$content=base64_decode(trim($content));
	    	file_put_contents($afile,$content);
	    	$mtimes[$afile]=1;
		}
	}
	sleep(1);
	echo "  setting baseline modify times.".PHP_EOL;
	foreach($mtimes as $afile=>$x){
		$mtimes[$afile]=filemtime($afile);
	}
	if(isWindows()){
		$afolder=preg_replace('/\//',"\\",$afolder);
		cmdResults("EXPLORER /E,\"{$afolder}\"");
		cmdResults("start /B \"link\" http://{$hosts[$chost]['name']}");
		cmdResults("start /B \"link\" http://{$hosts[$chost]['name']}/a");
	}
	return $afolder;
}
function buildHostUrl(){
	global $hosts;
	global $chost;
	if(isset($hosts[$chost]['secure']) && $hosts[$chost]['secure'] != 0){$http='https';}
	else{$http='http';}
	$url="{$http}://{$hosts[$chost]['name']}/php/index.php";
	return $url;
}
function fileChanged($afile){
	global $hosts;
	global $chost;
	global $mtimes;
	//echo $afile;exit;
	$filename=getFileName($afile);
	echo "  {$filename}";
	//exit;
	$content=@file_get_contents($afile);
	if(!strlen($content) && isWindows()){
		$content=getContents($afile);
		if(!strlen($content)){
    		echo " - failed to get content".PHP_EOL;
    		return;
		}
	}
	$content=encodeBase64($content);
	list($fname,$table,$field,$id,$ext)=preg_split('/\./',$filename);
	$postopts=array(
		'apikey'	=>$hosts[$chost]['apikey'],
		'username'	=>$hosts[$chost]['username'],
		'_noguid'	=>1,
		'_base64'	=>1,
		'_id'		=>$id,
		'timestamp'	=>$mtimes[$afile],
		'_action'	=>'postEdit',
		'_table'	=>$table,
		'_fields'	=>$field,
		$field		=>$content,
		'_return'	=>'XML',
		'-xml'		=>1
	);
	$url=buildHostUrl();
	$post=postURL($url,$postopts);
	file_put_contents('postedit_change.result',$post['body']);
POSTFILE:
	$xml = (array)readXML("<postedit>{$post['body']}</postedit>");
	$json=json_encode($xml);
	if(isset($post['curl_info']['http_code']) && $post['curl_info']['http_code'] != 200){
    	echo " - {$post['curl_info']['http_code']} error posting".PHP_EOL;
    	echo "{$json}".PHP_EOL;
    	exit;
	}
	elseif(isset($xml['fatal_error'])){
    	echo " - Fatal error posting".PHP_EOL;
    	echo "{$json}".PHP_EOL;
    	exit;
	}
	elseif(isset($xml['refresh_error'])){
    	echo " - Refresh error posting".PHP_EOL;
    	echo "   {$xml['refresh_error']}".PHP_EOL;
    	echo "   Refresh Now? Y or N: ";
		$s = stream_get_line(STDIN, 1024, PHP_EOL);
		$s=strtolower($s);
		if($s != 'n'){
        	writeFiles();
		}
	}
	elseif(isset($xml['error'])){
    	echo " - Error posting".PHP_EOL;
    	echo "   {$xml['error']}".PHP_EOL;
    	echo "   Overwrite Anyway? Y or N: ";
		$s = stream_get_line(STDIN, 1024, PHP_EOL);
		$s=strtolower($s);
		if($s == 'y'){
			$postopts['_overwrite']=1;
        	$post=postURL($url,$postopts);
        	goto POSTFILE;
		}
	}
	echo " - Successfully updated".PHP_EOL;
	//beep once on windows for success;
	if(isWindows()){echo "\x07";}
	return true;
}
function getContents($file){
	$file=preg_replace('/\//',"\\",$file);
	$cmd="file_get_contents.exe \"{$file}\"";
	//echo $cmd.PHP_EOL;
	$out=cmdResults($cmd);
	return $out['stdout'];
	$tries=0;
	while(!isset($out['stdout']) && $tries < 5){
    	sleep(1);
    	$out=cmdResults($cmd);
    	$tries++;
	}
	return $out['stdout'];

}
function selectHost(){
	global $cgroup;
	global $hosts;
	global $argv;
	if(!is_array($hosts)){getHosts();}
	$groups=getGroups();
	$lines=array();
	$x=1;
	$map=array();
	foreach($hosts as $name=>$host){
		if(strtolower($host['group']) != $cgroup){continue;}
    	$lines[]=" {$x}-{$name}";
    	$map[$x]=$name;
    	$x+=1;
	}
	//check for command line input
	if(isset($argv[2])){
		if(isset($map[$argv[2]])){return $map[$argv[2]];}
		if(isset($hosts[$argv[2]])){return $argv[2];}
	}
	while(1){
		echo "Select a Host:".PHP_EOL;
		echo implode("\r\n",$lines);
		echo "\r\nSelection: ";
		$s = stream_get_line(STDIN, 1024, PHP_EOL);
		$s=strtolower($s);
		if(isset($map[$s])){
			return $map[$s];
		}
		elseif(isset($hosts[$s])){
			return $s;
		}
		else{
        	echo "\r\nInvalid host entry".PHP_EOL;
		}
	}
}
function selectGroup(){
	global $argv;
	$groups=getGroups();
	$lines=array();
	$x=1;
	$map=array();
	foreach($groups as $group=>$cnt){
    	$lines[]=" {$x}-{$group}";
    	$map[$x]=$group;
    	$x+=1;
	}
	//check for command line input
	if(isset($argv[1])){
		if(isset($map[$argv[1]])){return $map[$argv[1]];}
		if(isset($groups[$argv[1]])){return $argv[1];}
	}
	while(1){
		echo "Select a Group:".PHP_EOL;
		echo implode("\r\n",$lines);
		echo "\r\nSelection: ";
		$s = stream_get_line(STDIN, 1024, PHP_EOL);
		$s=strtolower($s);
		if(isset($map[$s])){
			return $map[$s];
		}
		elseif(isset($groups[$s])){
			return $s;
		}
		else{
        	echo "\r\nInvalid group entry".PHP_EOL;
		}
	}
}
function getGroups(){
	global $hosts;
	if(!is_array($hosts)){getHosts();}
	$groups=array();
	foreach($hosts as $name=>$host){
		$group=strtolower($host['group']);
		if(isset($groups[$group])){$groups[$group]+=1;}
		else{$groups[$group]=1;}
	}
	ksort($groups);
	return $groups;
}
function getHosts(){
	global $progpath;
	global $hosts;
	if(!file_exists("$progpath/postedit.xml")){
		echo "Unable to find postedit.xml file";
		exit;
	}
	$xmldata=getFileContents("$progpath/postedit.xml");
	$xml = (array)readXML("<postedit>{$xmldata}</postedit>");
	$hosts=array();
	foreach($xml['hosts']->host as $xhost){
	    	$xhost=(array)$xhost;
	    	$name=$xhost['@attributes']['name'];
	    	foreach($xhost['@attributes'] as $k=>$v){
			$hosts[$name][$k]=$v;
			if(isset($xhost['@attributes']['alias'])){
            	$alias=$xhost['@attributes']['alias'];
            	$hosts[$alias]=$hosts[$name];
			}
		}
	}
	return;
}
?>
