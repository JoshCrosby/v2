<?php
/*
	PDF2text wrappers
*/
$progpath=dirname(__FILE__);
error_reporting(E_ALL & ~E_NOTICE);
include_once("{$progpath}/pdf/class.pdf2text.php");

//---------- begin function convertPDF2Txt--------------------------------------
/**
* @describe extracts text from a PDF file
* @param file string
*	The full file name and path
* @return txt text
*/
function convert2Txt($file){
	$ext=strtolower(getFileExtension($file));
	switch($ext){
    	case 'pdf':return convertPDF2Txt($file);break;
    	case 'doc':return convertDoc2Txt($file);break;
    	case 'docx':return convertDocx2Txt($file);break;
    	default:
    		return "convert2Txt Error: {$ext} files are not supported yet.";
    	break;
	}
}

//---------- begin function convertPDF2Txt--------------------------------------
/**
* @describe extracts text from a PDF file
* @param file string
*	The full file name and path
* @return txt text
* @exclude  - this function is for internal use only and thus excluded from the manual
*/
function convertPDF2Txt($file){
    $a = new PDF2Text();
    $a->setFilename($file);
    $a->decodePDF();
    return $a->output();
}
//---------- begin function convertDoc2Txt--------------------------------------
/**
* @describe extracts text from a Microsoft Doc file
* @param file string
*	The full file name and path
* @return txt text
* @exclude  - this function is for internal use only and thus excluded from the manual
*/
function convertDoc2Txt($file) {
    if($fh = fopen($file, "r")){
    	$headers = fread($fh, 0xA00);
    	$n1 = ( ord($headers[0x21C]) - 1 );
    	$n2 = ( ( ord($headers[0x21D]) - 8 ) * 256 );
    	$n3 = ( ( ord($headers[0x21E]) * 256 ) * 256 );
    	$n4 = ( ( ( ord($headers[0x21F]) * 256 ) * 256 ) * 256 );
    	$textLength = ($n1 + $n2 + $n3 + $n4);
    	$extracted_plaintext = fread($fh, $textLength);
    	$extracted_plaintext = preg_replace("/[^a-z0-9\s\,\.\-\n\r\t\@\/\_\(\)\!]/i","",$extracted_plaintext);
    	$extracted_plaintext = preg_replace("/\t+/"," ",$extracted_plaintext);
    	$lines=preg_split('/[\r\n]+/',trim($extracted_plaintext));
    	$txt='';
    	foreach($lines as $line){
			$len=strlen(trim($line));
        	if($len < 2){continue;}
        	//remove junk words - the longest word in English is 30 letters
        	$words=preg_split('/\ /',$line);
        	foreach($words as $i=>$word){
				if(strlen($word) > 30){unset($words[$i]);}
			}
        	$line=implode(' ',$words);
        	$txt .= $line."\n";
		}
		return $txt;
	}
    return '';
}
//---------- begin function convertDocx2Txt--------------------------------------
/**
* @describe extracts text from a Microsoft docx file
* @param file string
*	The full file name and path
* @return txt text
* @exclude  - this function is for internal use only and thus excluded from the manual
*/
function convertDocx2Txt($file){
    $striped_content = '';
    $content = '';
    $zip = zip_open($file);
    if (!$zip || is_numeric($zip)){return false;}
    while ($zip_entry = zip_read($zip)) {
        if (zip_entry_open($zip, $zip_entry) == FALSE){
			continue;
		}
        if(zip_entry_name($zip_entry) != "word/document.xml"){
			continue;
		}
        $content .= zip_entry_read($zip_entry, zip_entry_filesize($zip_entry));
        zip_entry_close($zip_entry);
    }
    zip_close($zip);
    $content = str_replace('</w:r></w:p></w:tc><w:tc>', " ", $content);
    $content = str_replace('</w:r></w:p>', "\r\n", $content);
    $striped_content = strip_tags($content);
    return $striped_content;
}