<?php
function import_xml(string $xml): SimpleXMLElement {
 if(stripos($xml,'<!DOCTYPE')!==false || stripos($xml,'<!ENTITY')!==false) throw new RuntimeException('External entities are not accepted.');
 $previous=libxml_use_internal_errors(true);$doc=simplexml_load_string($xml,SimpleXMLElement::class,LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($previous);
 if(!$doc) throw new RuntimeException('Invalid workbook XML.');return $doc;
}
function read_import(string $path,string $extension): array {
 if($extension==='csv') {
  $handle=fopen($path,'r');$rows=[];while(($line=fgetcsv($handle))!==false) { if(count($rows)>=5001 || count($line)>250) throw new RuntimeException('Maximum 5,000 rows and 250 columns.');$rows[]=$line; }fclose($handle);return $rows;
 }
 if($extension!=='xlsx' || !class_exists('ZipArchive')) throw new RuntimeException('XLSX requires the PHP ZIP extension. Legacy XLS files must first be saved as XLSX or CSV.');
 $zip=new ZipArchive();if($zip->open($path)!==true) throw new RuntimeException('Invalid XLSX archive.');
 try {
  $total=0;if($zip->numFiles>1000) throw new RuntimeException('Workbook has too many parts.');
  for($i=0;$i<$zip->numFiles;$i++) { $s=$zip->statIndex($i);$total+=$s['size'];if($s['size']>20*1024*1024 || $total>50*1024*1024) throw new RuntimeException('Workbook expansion exceeds limits.'); }
  $strings=[];$shared=$zip->getFromName('xl/sharedStrings.xml');
  if($shared!==false) foreach(import_xml($shared)->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->si as $si) { $parts=$si->xpath('.//*[local-name()="t"]');$strings[]=implode('',array_map('strval',$parts ?: [])); }
  $book=import_xml((string)$zip->getFromName('xl/workbook.xml'));$sheets=$book->xpath('//*[local-name()="sheet"]');$first=$sheets[0] ?? null;
  if(!$first) throw new RuntimeException('No worksheet found.');$rid=(string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
  $rels=import_xml((string)$zip->getFromName('xl/_rels/workbook.xml.rels'));$target='';foreach($rels->xpath('//*[local-name()="Relationship"]') ?: [] as $rel) if((string)$rel['Id']===$rid) $target=(string)$rel['Target'];
  if(!$target || str_contains($target,'..') || str_contains($target,':')) throw new RuntimeException('Invalid worksheet path.');
  $sheetPath=str_starts_with($target,'/')?ltrim($target,'/'):'xl/'.$target;
  $sheet=import_xml((string)$zip->getFromName($sheetPath));$rows=[];
  foreach($sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $r) {
   if(count($rows)>=5001) throw new RuntimeException('Maximum 5,000 rows.');$line=[];
   foreach($r->xpath('./*[local-name()="c"]') ?: [] as $cell) {
    preg_match('/^([A-Z]+)/',(string)$cell['r'],$m);$index=0;foreach(str_split($m[1] ?? 'A') as $letter) $index=$index*26+ord($letter)-64;$index--;
    if($index>=250) throw new RuntimeException('Maximum 250 columns.');
    $v=$cell->xpath('./*[local-name()="v"]');$value=(string)($v[0] ?? '');
    if((string)$cell['t']==='s') $value=$strings[(int)$value] ?? '';
    if((string)$cell['t']==='inlineStr') $value=implode('',array_map('strval',$cell->xpath('.//*[local-name()="t"]') ?: []));
    $line[$index]=$value;
   }
   if($line) { $filled=[];for($i=0;$i<=max(array_keys($line));$i++) $filled[]=$line[$i] ?? '';$rows[]=$filled; }
  }return $rows;
 }finally { $zip->close(); }
}
