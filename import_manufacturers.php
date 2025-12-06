<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$arquivoConfig = __DIR__ . '/config.php';
if (!file_exists($arquivoConfig)) { die('config.php not found'); }
require_once $arquivoConfig;

$csrf_name = '_csrf';
if (empty($_SESSION[$csrf_name])) { $_SESSION[$csrf_name] = bin2hex(random_bytes(16)); }
$csrf_token = $_SESSION[$csrf_name];

$company_id = 1;
$erro = '';
$ok = '';

function detect_delimiter($path) { $h=fopen($path,'r'); if(!$h) return ','; $line=fgets($h); fclose($h); if($line===false) return ','; $c=[','=>substr_count($line,','),';'=>substr_count($line,';'),'\t'=>substr_count($line, "\t")]; arsort($c); return array_key_first($c); }
function read_csv_sample($path,$delimiter,$limit=2000){ $rows=[]; if(($h=fopen($path,'r'))!==false){$i=0; while(($d=fgetcsv($h,0,$delimiter))!==false && $i<$limit){$rows[]=$d; $i++;} fclose($h);} return $rows; }
function xlsx_read_shared_strings($zip){ $out=[]; $xml=$zip->getFromName('xl/sharedStrings.xml'); if($xml===false) return $out; if(preg_match_all('/<si>(.*?)<\/si>/s',$xml,$m)){ foreach($m[1] as $si){ $t=''; if(preg_match_all('/<t[^>]*>(.*?)<\/t>/s',$si,$tm)){ foreach($tm[1] as $p){ $t.=html_entity_decode(strip_tags($p),ENT_QUOTES|ENT_XML1,'UTF-8'); } } $out[]=$t; } } return $out; }
function xlsx_col_to_index($cellRef){ if(!preg_match('/([A-Z]+)[0-9]+/i',$cellRef,$m)) return 0; $col=strtoupper($m[1]); $n=0; for($i=0;$i<strlen($col);$i++){ $n=$n*26 + (ord($col[$i])-64); } return $n-1; }
function xlsx_list_sheets($path){ $out=[]; $zip=new ZipArchive(); if($zip->open($path)!==true) return $out; $wb=$zip->getFromName('xl/workbook.xml'); $rels=$zip->getFromName('xl/_rels/workbook.xml.rels'); $map=[]; if($rels!==false && preg_match_all('/<Relationship[^>]*Id="(rId\d+)"[^>]*Target="([^"]+)"/i',$rels,$rm,PREG_SET_ORDER)){ foreach($rm as $r){ $map[$r[1]]='xl/'.ltrim($r[2],'/'); } } if($wb!==false && preg_match_all('/<sheet[^>]*name="([^"]+)"[^>]*r:id="(rId\d+)"/i',$wb,$sm,PREG_SET_ORDER)){ foreach($sm as $s){ $name=html_entity_decode($s[1],ENT_QUOTES|ENT_XML1,'UTF-8'); $rid=$s[2]; $path=isset($map[$rid])?$map[$rid]:'xl/worksheets/sheet1.xml'; $out[]=['name'=>$name,'path'=>$path]; } } $zip->close(); return $out; }
function read_xlsx_rows($path,$maxRows=0,$sheetPath='xl/worksheets/sheet1.xml'){
  $rows=[]; $zip=new ZipArchive(); if($zip->open($path)!==true) return $rows; $sheet=$zip->getFromName($sheetPath); if($sheet===false){$zip->close(); return $rows;} $shared=xlsx_read_shared_strings($zip); $zip->close();
  if(preg_match_all('/<row[^>]*>(.*?)<\/row>/s',$sheet,$rm)){
    $count=0; foreach($rm[1] as $rxml){ $row=[]; if(preg_match_all('/<c([^>]*)>(.*?)<\/c>/s',$rxml,$cm,PREG_SET_ORDER)){
      foreach($cm as $c){ $attrs=$c[1]; $cbody=$c[2]; if(!preg_match('/r="([A-Z0-9]+)"/i',$attrs,$refm)) continue; $colIdx=xlsx_col_to_index($refm[1]); $type=''; if(preg_match('/\bt="(\w+)"/i',$attrs,$tm)) $type=strtolower($tm[1]); $val=''; if($type==='s'){ if(preg_match('/<v>(\d+)<\/v>/',$cbody,$vm)){ $sid=(int)$vm[1]; $val=isset($shared[$sid])?$shared[$sid]:''; } } elseif($type==='inlinestr'){ if(preg_match('/<t[^>]*>(.*?)<\/t>/s',$cbody,$tm2)){ $val=html_entity_decode($tm2[1],ENT_QUOTES|ENT_XML1,'UTF-8'); } } else { if(preg_match('/<v>(.*?)<\/v>/',$cbody,$vm)){ $val=$vm[1]; } } $row[$colIdx]=$val; }
    }
    if(!empty($row)){ ksort($row); } $rows[]=$row; $count++; if($maxRows>0 && $count>=$maxRows) break; }
  }
  return $rows;
}

$step = isset($_POST['step']) ? (string)$_POST['step'] : 'upload';
$tmpfile = isset($_POST['tmpfile']) ? (string)$_POST['tmpfile'] : '';
$sheetpath = isset($_POST['sheetpath']) ? (string)$_POST['sheetpath'] : '';
$has_header = isset($_POST['has_header']) && $_POST['has_header'] === '1';

if ($step==='upload' && $_SERVER['REQUEST_METHOD']==='POST'){
  if (!isset($_POST[$csrf_name]) || !hash_equals($_SESSION[$csrf_name], (string)$_POST[$csrf_name])) { $erro='Session expired. Please reload.'; }
  else {
    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) { $erro='Please select a CSV/XLSX file.'; }
    else { $ext=strtolower(pathinfo($_FILES['csv']['name'], PATHINFO_EXTENSION)); if($ext!=='csv' && $ext!=='xlsx'){ $erro='Only CSV or XLSX files are supported.'; }
      if(!$erro){ $tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'import_manufacturers_'.bin2hex(random_bytes(6)).'.'.$ext; if(!move_uploaded_file($_FILES['csv']['tmp_name'],$tmp)){ $erro='Failed to store uploaded file.'; } else { $tmpfile=$tmp; $step='map'; } }
    }
  }
}

$preview=[]; $delimiter=',';
if ($step==='map' && $tmpfile && file_exists($tmpfile)){
  $ext=strtolower(pathinfo($tmpfile, PATHINFO_EXTENSION));
  if($ext==='xlsx'){ $sheets=xlsx_list_sheets($tmpfile); if(!$sheetpath && !empty($sheets)) $sheetpath=$sheets[0]['path']; $preview=read_xlsx_rows($tmpfile, 15, $sheetpath ?: 'xl/worksheets/sheet1.xml'); }
  else { $delimiter=detect_delimiter($tmpfile); $preview=read_csv_sample($tmpfile, $delimiter, 15); }
}

if ($step==='process' && $_SERVER['REQUEST_METHOD']==='POST'){
  if (!isset($_POST[$csrf_name]) || !hash_equals($_SESSION[$csrf_name], (string)$_POST[$csrf_name])) { $erro='Session expired. Please reload.'; }
  else {
    $tmpfile=(string)($_POST['tmpfile']??'');
    $sheetpath=(string)($_POST['sheetpath']??'');
    $has_header = isset($_POST['has_header']) && $_POST['has_header']==='1';
    $delimiter=(string)($_POST['delimiter']??',');
    $map_desc = isset($_POST['map_description']) ? (int)$_POST['map_description'] : -1;
    if ($map_desc < 0) { $erro='Please map the Description column.'; }
    if (!$erro && !file_exists($tmpfile)) { $erro='Temporary file not found. Please upload again.'; }
    if (!$erro) {
      $to_insert=[]; $seen=[];
      $push=function($desc) use (&$to_insert,&$seen){
        $desc=trim((string)$desc); if($desc==='') return;
        $key=mb_strtoupper($desc); if(isset($seen[$key])) return; $seen[$key]=true;
        $to_insert[]=['description'=>$key,'is_active'=>1]; // store uppercase
      };
      $ext=strtolower(pathinfo($tmpfile, PATHINFO_EXTENSION));
      if($ext==='xlsx'){
        $all=read_xlsx_rows($tmpfile,0,$sheetpath ?: 'xl/worksheets/sheet1.xml'); $start=$has_header?1:0; for($i=$start;$i<count($all);$i++){ $row=$all[$i]; $desc=$row[$map_desc] ?? ''; $push($desc); }
      } else {
        if(($h=fopen($tmpfile,'r'))!==false){ $i=0; while(($data=fgetcsv($h,0,$delimiter))!==false){ $i++; if($has_header && $i===1) continue; $desc=$data[$map_desc] ?? ''; $push($desc); } fclose($h);} else { $erro='Could not open uploaded file.'; }
      }
      if(!$erro && !empty($to_insert)){
        // load existing
        $existing=[]; if($stmt=$conn->prepare('SELECT description FROM manufacturers WHERE company_id=?')){ $stmt->bind_param('i',$company_id); if($stmt->execute()){ $res=$stmt->get_result(); while($r=$res->fetch_assoc()){ $existing[mb_strtoupper(trim($r['description']))]=true; } $res->free(); } $stmt->close(); }
        $conn->begin_transaction();
        try{
          $sql='INSERT INTO manufacturers (company_id, description, is_active, created_at, updated_at) VALUES (?,?,?, NOW(), NOW())';
          $stmt=$conn->prepare($sql); if(!$stmt) throw new Exception('Prepare failed');
          $inserted=0; foreach($to_insert as $m){ $key=mb_strtoupper(trim($m['description'])); if(isset($existing[$key])) continue; $stmt->bind_param('isi',$company_id,$m['description'],$m['is_active']); if(!$stmt->execute()) throw new Exception('Insert failed'); $existing[$key]=true; $inserted++; }
          $stmt->close(); $conn->commit();
          if($inserted>0){ $ok='Imported '.$inserted.' manufacturer(s). Duplicates/existing skipped.'; } else { $ok='No new manufacturers to insert.'; }
          @unlink($tmpfile); $tmpfile=''; $step='upload';
        } catch(Throwable $e){ $conn->rollback(); $erro='Import failed. No rows were saved.'; }
      }
    }
  }
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Import Manufacturers</title>
  <style>
    :root { --bg:#0f172a; --muted:#94a3b8; --text:#e2e8f0; }
    body{margin:0; font-family: system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Helvetica Neue",Arial; background: var(--bg); color:var(--text)}
    .container{max-width:900px; margin:24px auto; padding:0 24px}
    .card{margin-top:22px; padding:18px; border:1px solid rgba(148,163,184,.16); border-radius:14px; background: rgba(255,255,255,.02)}
    input[type=text], input[type=number], select{padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,.22); background:#0b1220; color:var(--text); width:100%; height:40px; font-size:14px}
    .btn{appearance:none; display:inline-flex; align-items:center; justify-content:center; border:1px solid #3b4252; background:#0f172a; color:#e5e7eb; font-weight:600; font-size:13px; height:34px; padding:0 12px; border-radius:8px; cursor:pointer; text-decoration:none}
    .btn:hover{background:#0b1220}
    .grid{display:grid; grid-template-columns: repeat(12, 1fr); gap:8px}
    .col-12{grid-column: span 12}
    .muted{color:var(--muted)}
    .mapgrid{display:grid; grid-template-columns: 220px 1fr; column-gap:10px; row-gap:10px}
    .mapgrid .left{display:flex; align-items:center; justify-content:flex-start; color:var(--muted)}
    .mapgrid .right select{width:100%}
    .msg{margin:8px 0 0; font-size:14px}
    .error{color:#ef4444}
    .ok{color:#22c55e}
  </style>
</head>
<body>
  <div class="container">
    <div><a class="btn" href="manufacturers.php">Back</a></div>
    <section class="card">
      <h2 style="margin:0 0 10px 0">Import Manufacturers</h2>
      <?php if ($erro): ?><div class="msg error"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="msg ok"><?php echo htmlspecialchars($ok, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

      <?php if ($step==='upload'): ?>
      <form method="post" enctype="multipart/form-data" class="grid">
        <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="step" value="upload">
        <div class="col-12">
          <label class="muted">CSV/XLSX file (exported from Excel)</label>
          <input type="file" name="csv" accept=".csv,.xlsx" required>
        </div>
        <div class="col-12"><button class="btn" type="submit">Upload</button></div>
      </form>
      <p class="muted" style="margin-top:8px">Required: Manufacturer. Defaults: Active = Yes.</p>
      <?php elseif ($step==='map'): ?>
      <form method="post" class="grid">
        <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="step" value="process">
        <input type="hidden" name="tmpfile" value="<?php echo htmlspecialchars($tmpfile, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="delimiter" value="<?php echo htmlspecialchars($delimiter, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="col-12 mapgrid">
          <?php if (strtolower(pathinfo($tmpfile, PATHINFO_EXTENSION))==='xlsx'): ?>
          <div class="left">Sheet</div>
          <div class="right">
            <select name="sheetpath" required>
              <?php $sheets=xlsx_list_sheets($tmpfile); foreach($sheets as $idx=>$s): $sel=($sheetpath && $sheetpath===$s['path'])?'selected':((!$sheetpath && $idx===0)?'selected':''); ?>
              <option value="<?php echo htmlspecialchars($s['path'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="left">First row is header</div>
          <div class="right"><label style="display:flex; align-items:center; gap:8px"><input type="checkbox" name="has_header" value="1" checked></label></div>
          <div class="left">Manufacturer</div>
          <div class="right">
            <select name="map_description" required>
              <option value="">Select...</option>
              <?php $first=$preview[0]??[]; foreach($first as $i=>$n): ?>
                <option value="<?php echo (int)$i; ?>"><?php echo htmlspecialchars($n!==''?$n:('Column '.($i+1)), ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="col-12"><button class="btn" type="submit">Validate & Import</button> <a class="btn" href="import_manufacturers.php">Cancel</a></div>
      </form>
      <?php endif; ?>
    </section>
  </div>
</body>
</html>
