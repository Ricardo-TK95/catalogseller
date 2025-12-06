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

// Get sales_rep from URL or POST
$sales_rep_id = isset($_GET['sales_rep']) ? (int)$_GET['sales_rep'] : (isset($_POST['sales_rep_id']) ? (int)$_POST['sales_rep_id'] : 0);

// Load sales reps for dropdown
$sales_reps = [];
if ($stmt = $conn->prepare('SELECT id, full_name FROM sales_reps WHERE company_id=? AND is_active=1 ORDER BY full_name')) {
  $stmt->bind_param('i', $company_id);
  if ($stmt->execute()) { 
    $res = $stmt->get_result(); 
    while($r = $res->fetch_assoc()){ $sales_reps[] = $r; } 
    $res->free(); 
  }
  $stmt->close();
}

// Reuse helpers from import_products (simplified local copies)
function detect_delimiter($path) {
  $h = fopen($path, 'r'); if(!$h) return ','; $line=fgets($h); fclose($h); if($line===false) return ','; $c=[','=>substr_count($line,','),';'=>substr_count($line,';'),'\t'=>substr_count($line,"\t")]; arsort($c); return array_key_first($c);
}
function read_csv_sample($path, $delimiter, $limit = 2000) {
  $rows=[]; if(($h=fopen($path,'r'))!==false){$i=0; while(($d=fgetcsv($h,0,$delimiter))!==false && $i<$limit){$rows[]=$d; $i++;} fclose($h);} return $rows;
}
function xlsx_read_shared_strings($zip){$out=[]; $xml=$zip->getFromName('xl/sharedStrings.xml'); if($xml===false) return $out; if(preg_match_all('/<si>(.*?)<\/si>/s',$xml,$m)){foreach($m[1] as $si){$t=''; if(preg_match_all('/<t[^>]*>(.*?)<\/t>/s',$si,$tm)){foreach($tm[1] as $p){$t.=html_entity_decode(strip_tags($p),ENT_QUOTES|ENT_XML1,'UTF-8');}} $out[]=$t;}} return $out;}
function xlsx_col_to_index($cellRef){if(!preg_match('/([A-Z]+)[0-9]+/i',$cellRef,$m)) return 0; $col=strtoupper($m[1]); $n=0; for($i=0;$i<strlen($col);$i++){ $n=$n*26+(ord($col[$i])-64);} return $n-1; }
function xlsx_list_sheets($path){$out=[]; $zip=new ZipArchive(); if($zip->open($path)!==true) return $out; $wb=$zip->getFromName('xl/workbook.xml'); $rels=$zip->getFromName('xl/_rels/workbook.xml.rels'); $map=[]; if($rels!==false && preg_match_all('/<Relationship[^>]*Id="(rId\d+)"[^>]*Target="([^"]+)"/i',$rels,$rm,PREG_SET_ORDER)){foreach($rm as $r){$map[$r[1]]='xl/'.ltrim($r[2],'/');}} if($wb!==false && preg_match_all('/<sheet[^>]*name="([^"]+)"[^>]*r:id="(rId\d+)"/i',$wb,$sm,PREG_SET_ORDER)){foreach($sm as $s){$name=htmlentities($s[1]); $rid=$s[2]; $path=isset($map[$rid])?$map[$rid]:'xl/worksheets/sheet1.xml'; $out[]=['name'=>html_entity_decode($s[1],ENT_QUOTES|ENT_XML1,'UTF-8'),'path'=>$path];}} $zip->close(); return $out;}
function read_xlsx_rows($path,$maxRows=0,$sheetPath='xl/worksheets/sheet1.xml'){
  $rows=[]; $zip=new ZipArchive(); if($zip->open($path)!==true) return $rows; $sheet=$zip->getFromName($sheetPath); if($sheet===false){$zip->close(); return $rows;} $shared=xlsx_read_shared_strings($zip); $zip->close();
  if(preg_match_all('/<row[^>]*>(.*?)<\/row>/s',$sheet,$rm)){
    $count=0; foreach($rm[1] as $rxml){ $row=[];
      if(preg_match_all('/<c([^>]*)>(.*?)<\/c>/s',$rxml,$cm,PREG_SET_ORDER)){
        foreach($cm as $c){ $attrs=$c[1]; $cbody=$c[2];
          if(!preg_match('/r="([A-Z0-9]+)"/i',$attrs,$refm)) continue; $colIdx=xlsx_col_to_index($refm[1]);
          $type=''; if(preg_match('/\bt="(\w+)"/i',$attrs,$tm)) $type=strtolower($tm[1]);
          $val=''; if($type==='s'){ if(preg_match('/<v>(\d+)<\/v>/',$cbody,$vm)){ $sid=(int)$vm[1]; $val=isset($shared[$sid])?$shared[$sid]:''; } }
          elseif($type==='inlinestr'){ if(preg_match('/<t[^>]*>(.*?)<\/t>/s',$cbody,$tm2)){ $val=html_entity_decode($tm2[1],ENT_QUOTES|ENT_XML1,'UTF-8'); } }
          else { if(preg_match('/<v>(.*?)<\/v>/',$cbody,$vm)){ $val=$vm[1]; } }
          $row[$colIdx]=$val; }
      }
      if(!empty($row)){ ksort($row); } $rows[]=$row; $count++; if($maxRows>0 && $count>=$maxRows) break; }
  }
  return $rows;
}

$step = isset($_POST['step']) ? (string)$_POST['step'] : 'upload';
$tmpfile = isset($_POST['tmpfile']) ? (string)$_POST['tmpfile'] : '';
$sheetpath = isset($_POST['sheetpath']) ? (string)$_POST['sheetpath'] : '';
$has_header = isset($_POST['has_header']) && $_POST['has_header'] === '1';

if ($step === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST[$csrf_name]) || !hash_equals($_SESSION[$csrf_name], (string)$_POST[$csrf_name])) { $erro='Session expired. Please reload.'; }
  else {
    $sales_rep_id = isset($_POST['sales_rep_id']) ? (int)$_POST['sales_rep_id'] : 0;
    if ($sales_rep_id <= 0) { $erro='Please select a Sales Representative.'; }
    if (!$erro && (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK)) { $erro='Please select a CSV/XLSX file.'; }
    else if (!$erro) {
      $ext=strtolower(pathinfo($_FILES['csv']['name'], PATHINFO_EXTENSION)); if($ext!=='csv' && $ext!=='xlsx'){ $erro='Only CSV or XLSX files are supported.'; }
      if(!$erro){ $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'import_stores_'.bin2hex(random_bytes(6)).'.'.$ext; if(!move_uploaded_file($_FILES['csv']['tmp_name'],$tmp)){ $erro='Failed to store uploaded file.'; } else { $tmpfile=$tmp; $step='map'; }}
    }
  }
}

$preview=[]; $delimiter=',';
if ($step==='map' && $tmpfile && file_exists($tmpfile)) {
  $ext=strtolower(pathinfo($tmpfile, PATHINFO_EXTENSION));
  if($ext==='xlsx'){ $sheets=xlsx_list_sheets($tmpfile); if(!$sheetpath && !empty($sheets)) $sheetpath=$sheets[0]['path']; $preview=read_xlsx_rows($tmpfile, 15, $sheetpath ?: 'xl/worksheets/sheet1.xml'); }
  else { $delimiter=detect_delimiter($tmpfile); $preview=read_csv_sample($tmpfile, $delimiter, 15); }
}

if ($step==='process' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!isset($_POST[$csrf_name]) || !hash_equals($_SESSION[$csrf_name], (string)$_POST[$csrf_name])) { $erro='Session expired. Please reload.'; }
  else {
    $tmpfile = (string)($_POST['tmpfile'] ?? '');
    $sheetpath = (string)($_POST['sheetpath'] ?? '');
    $has_header = isset($_POST['has_header']) && $_POST['has_header']==='1';
    $delimiter = (string)($_POST['delimiter'] ?? ',');
    $sales_rep_id = isset($_POST['sales_rep_id']) ? (int)$_POST['sales_rep_id'] : 0;
    
    if ($sales_rep_id <= 0) { $erro = 'Please select a Sales Representative.'; }
    
    $map = [
      'store_name' => isset($_POST['map_store_name']) ? (int)$_POST['map_store_name'] : -1,
      'cod' => isset($_POST['map_cod']) ? (int)$_POST['map_cod'] : -1,
      'website' => isset($_POST['map_website']) ? (int)$_POST['map_website'] : -1,
      'contact_name' => isset($_POST['map_contact_name']) ? (int)$_POST['map_contact_name'] : -1,
      'phone_ddd' => isset($_POST['map_phone_ddd']) ? (int)$_POST['map_phone_ddd'] : -1,
      'phone' => isset($_POST['map_phone']) ? (int)$_POST['map_phone'] : -1,
      'email' => isset($_POST['map_email']) ? (int)$_POST['map_email'] : -1,
      'address_line1' => isset($_POST['map_address_line1']) ? (int)$_POST['map_address_line1'] : -1,
      'city' => isset($_POST['map_city']) ? (int)$_POST['map_city'] : -1,
      'state' => isset($_POST['map_state']) ? (int)$_POST['map_state'] : -1,
      'zip_code' => isset($_POST['map_zip_code']) ? (int)$_POST['map_zip_code'] : -1,
    ];
    
    if ($map['store_name'] < 0) { $erro = 'Please map the Store Name column.'; }
    if (!$erro && (!file_exists($tmpfile))) { $erro = 'Temporary file not found. Please upload again.'; }
    
    if (!$erro) {
      $to_insert = [];
      $seen_by_cod = [];
      $seen_by_name = [];
      $push = function($data) use (&$to_insert, &$seen_by_cod, &$seen_by_name, $sales_rep_id, $company_id){
        $store_name = trim((string)($data['store_name'] ?? ''));
        if ($store_name==='') return;
        
        $cod_key = trim((string)($data['cod'] ?? ''));
        $name_key = mb_strtoupper($store_name);
        
        // Check for duplicates within file: first by cod (if provided), then by name
        $exists = false;
        if ($cod_key !== '') {
          $cod_upper = mb_strtoupper($cod_key);
          if (isset($seen_by_cod[$cod_upper])) {
            $exists = true;
          }
        }
        if (!$exists && isset($seen_by_name[$name_key])) {
          $exists = true;
        }
        if ($exists) return; // Skip duplicate within file
        
        // Mark as seen
        if ($cod_key !== '') {
          $seen_by_cod[mb_strtoupper($cod_key)] = true;
        }
        $seen_by_name[$name_key] = true;
        
        // Concatenate 1 + DDD + Phone for phone
        $phone_ddd = trim((string)($data['phone_ddd'] ?? ''));
        $phone_num = trim((string)($data['phone'] ?? ''));
        $phone_full = ($phone_ddd !== '' && $phone_num !== '') ? ('1' . $phone_ddd . $phone_num) : '';
        
        $to_insert[] = [
          'store_name' => $store_name,
          'cod' => $cod_key,
          'website' => trim((string)($data['website'] ?? '')),
          'contact_name' => trim((string)($data['contact_name'] ?? '')),
          'phone' => $phone_full,
          'email' => trim((string)($data['email'] ?? '')),
          'address_line1' => trim((string)($data['address_line1'] ?? '')),
          'city' => trim((string)($data['city'] ?? '')),
          'state' => trim((string)($data['state'] ?? '')),
          'zip_code' => trim((string)($data['zip_code'] ?? ''))
        ];
      };
      $ext=strtolower(pathinfo($tmpfile, PATHINFO_EXTENSION));
      if($ext==='xlsx'){
        $all = read_xlsx_rows($tmpfile, 0, $sheetpath ?: 'xl/worksheets/sheet1.xml');
        $start = $has_header ? 1 : 0;
        for ($i=$start;$i<count($all);$i++) { 
          $row=$all[$i]; 
          $data = [
            'store_name' => $row[$map['store_name']] ?? '',
            'cod' => $map['cod'] >= 0 ? ($row[$map['cod']] ?? '') : '',
            'website' => $map['website'] >= 0 ? ($row[$map['website']] ?? '') : '',
            'contact_name' => $map['contact_name'] >= 0 ? ($row[$map['contact_name']] ?? '') : '',
            'phone_ddd' => $map['phone_ddd'] >= 0 ? ($row[$map['phone_ddd']] ?? '') : '',
            'phone' => $map['phone'] >= 0 ? ($row[$map['phone']] ?? '') : '',
            'email' => $map['email'] >= 0 ? ($row[$map['email']] ?? '') : '',
            'address_line1' => $map['address_line1'] >= 0 ? ($row[$map['address_line1']] ?? '') : '',
            'city' => $map['city'] >= 0 ? ($row[$map['city']] ?? '') : '',
            'state' => $map['state'] >= 0 ? ($row[$map['state']] ?? '') : '',
            'zip_code' => $map['zip_code'] >= 0 ? ($row[$map['zip_code']] ?? '') : '',
          ];
          $push($data); 
        }
      } else {
        if(($h=fopen($tmpfile,'r'))!==false){ 
          $i=0; 
          while(($data=fgetcsv($h,0,$delimiter))!==false){ 
            $i++; 
            if($has_header && $i===1) continue; 
            $row_data = [
              'store_name' => $data[$map['store_name']] ?? '',
              'cod' => $map['cod'] >= 0 ? ($data[$map['cod']] ?? '') : '',
              'website' => $map['website'] >= 0 ? ($data[$map['website']] ?? '') : '',
              'contact_name' => $map['contact_name'] >= 0 ? ($data[$map['contact_name']] ?? '') : '',
              'phone_ddd' => $map['phone_ddd'] >= 0 ? ($data[$map['phone_ddd']] ?? '') : '',
              'phone' => $map['phone'] >= 0 ? ($data[$map['phone']] ?? '') : '',
              'email' => $map['email'] >= 0 ? ($data[$map['email']] ?? '') : '',
              'address_line1' => $map['address_line1'] >= 0 ? ($data[$map['address_line1']] ?? '') : '',
              'city' => $map['city'] >= 0 ? ($data[$map['city']] ?? '') : '',
              'state' => $map['state'] >= 0 ? ($data[$map['state']] ?? '') : '',
              'zip_code' => $map['zip_code'] >= 0 ? ($data[$map['zip_code']] ?? '') : '',
            ];
            $push($row_data); 
          } 
          fclose($h);
        } else { 
          $erro='Could not open uploaded file.'; 
        }
      }
      if (!$erro && !empty($to_insert)) {
        // Load existing stores (by cod and store_name) to avoid duplicates
        $existing_by_cod = [];
        $existing_by_name = [];
        if ($stmt=$conn->prepare('SELECT cod, store_name FROM stores WHERE company_id=?')){ 
          $stmt->bind_param('i',$company_id); 
          if($stmt->execute()){ 
            $res=$stmt->get_result(); 
            while($r=$res->fetch_assoc()){ 
              $cod_key = trim((string)($r['cod'] ?? ''));
              $name_key = mb_strtoupper(trim((string)($r['store_name'] ?? '')));
              if ($cod_key !== '') {
                $existing_by_cod[mb_strtoupper($cod_key)] = true;
              }
              if ($name_key !== '') {
                $existing_by_name[$name_key] = true;
              }
            } 
            $res->free(); 
          } 
          $stmt->close(); 
        }
        $conn->begin_transaction();
        try {
          // Campos do sistema: company_id, sales_rep_id
          // Campos que vêm do arquivo: cod, store_name, website, email, contact_name, address_line1, city, state, zip_code, phone
          // Campos automáticos: id, status, is_active, created_at, updated_at
          $sql='INSERT INTO stores (company_id, sales_rep_id, cod, store_name, website, email, contact_name, address_line1, city, state, zip_code, phone) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)';
          $stmt=$conn->prepare($sql); 
          if(!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
          $inserted = 0; 
          $skipped_existing = 0;
          foreach($to_insert as $s){
            $cod_key = trim((string)($s['cod'] ?? ''));
            $name_key = mb_strtoupper(trim((string)($s['store_name'] ?? '')));
            
            // Check if exists: first by cod (if cod is provided), then by store_name
            $exists = false;
            if ($cod_key !== '') {
              $cod_upper = mb_strtoupper($cod_key);
              if (isset($existing_by_cod[$cod_upper])) {
                $exists = true;
              }
            }
            if (!$exists && $name_key !== '' && isset($existing_by_name[$name_key])) {
              $exists = true;
            }
            
            if ($exists) { 
              $skipped_existing++; 
              continue; 
            }
            
            // 12 campos: company_id(i), sales_rep_id(i), cod(s), store_name(s), website(s), email(s), contact_name(s), address_line1(s), city(s), state(s), zip_code(s), phone(s)
            $stmt->bind_param('iissssssssss',
              $company_id,
              $sales_rep_id,
              $s['cod'],
              $s['store_name'],
              $s['website'],
              $s['email'],
              $s['contact_name'],
              $s['address_line1'],
              $s['city'],
              $s['state'],
              $s['zip_code'],
              $s['phone']
            );
            if(!$stmt->execute()) throw new Exception('Insert failed: ' . $stmt->error);
            
            // Add to existing lists to avoid duplicates within the same import
            if ($cod_key !== '') {
              $existing_by_cod[mb_strtoupper($cod_key)] = true;
            }
            if ($name_key !== '') {
              $existing_by_name[$name_key] = true;
            }
            $inserted++;
          }
          $stmt->close(); 
          $conn->commit();
          if ($inserted>0) { 
            $ok='Imported '.$inserted.' store(s). ' . ($skipped_existing > 0 ? $skipped_existing . ' duplicate(s) skipped.' : ''); 
          }
          else { 
            $ok='No new stores were inserted. All rows were empty or already existed.'; 
          }
          @unlink($tmpfile); 
          $tmpfile=''; 
          $step='upload';
        } catch(Throwable $e){ 
          $conn->rollback(); 
          $erro='Import failed: ' . $e->getMessage(); 
        }
      } else if (!$erro && empty($to_insert)) {
        $erro = 'No valid data found in file.';
      }
    }
  }
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Import Stores</title>
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
    .col-8{grid-column: span 8}
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
    <div><a class="btn" href="stores.php<?php echo $sales_rep_id > 0 ? '?q_sales_rep=' . $sales_rep_id : ''; ?>">Back</a></div>
    <section class="card">
      <h2 style="margin:0 0 10px 0">Import Stores by Sales Representative</h2>
      <?php if ($erro): ?><div class="msg error"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="msg ok"><?php echo htmlspecialchars($ok, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

      <?php if ($step === 'upload'): ?>
      <form method="post" enctype="multipart/form-data" class="grid">
        <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="step" value="upload">
        <div class="col-12">
          <label class="muted">Sales Representative</label>
          <select name="sales_rep_id" required>
            <option value="">Select...</option>
            <?php foreach ($sales_reps as $sr): ?>
            <option value="<?php echo (int)$sr['id']; ?>" <?php echo ($sales_rep_id===(int)$sr['id']?'selected':''); ?>><?php echo htmlspecialchars($sr['full_name'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="muted">CSV/XLSX file (exported from Excel)</label>
          <input type="file" name="csv" accept=".csv,.xlsx" required>
        </div>
        <div class="col-12">
          <button class="btn" type="submit">Upload</button>
        </div>
      </form>
      <p class="muted" style="margin-top:8px">Required: Store Name. Optional: Cod, Website, Contact Name, Phone DDD + Phone, Email, Address Line 1, City, State, Zip Code.</p>
      <?php elseif ($step === 'map'): ?>
      <form method="post" class="grid">
        <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="step" value="process">
        <input type="hidden" name="tmpfile" value="<?php echo htmlspecialchars($tmpfile, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="delimiter" value="<?php echo htmlspecialchars($delimiter, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="sales_rep_id" value="<?php echo (int)$sales_rep_id; ?>">
        <div class="col-12">
          <p class="muted" style="margin:0 0 10px 0"><strong>Sales Representative:</strong> <?php 
            $sr_name = 'N/A';
            foreach ($sales_reps as $sr) {
              if ((int)$sr['id'] === $sales_rep_id) {
                $sr_name = htmlspecialchars($sr['full_name'], ENT_QUOTES, 'UTF-8');
                break;
              }
            }
            echo $sr_name;
          ?></p>
        </div>
        <div class="col-12 mapgrid">
          <?php if (strtolower(pathinfo($tmpfile, PATHINFO_EXTENSION)) === 'xlsx'): ?>
            <div class="left">Sheet</div>
            <div class="right">
              <select name="sheetpath" required>
                <?php $sheets = xlsx_list_sheets($tmpfile); foreach ($sheets as $idx=>$s): $sel = ($sheetpath && $sheetpath===$s['path']) ? 'selected' : ((!$sheetpath && $idx===0)?'selected':''); ?>
                <option value="<?php echo htmlspecialchars($s['path'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
          <div class="left">First row is header</div>
          <div class="right"><label style="display:flex; align-items:center; gap:8px"><input type="checkbox" name="has_header" value="1" checked></label></div>
          <div class="left">Store Name <span style="color:#ef4444">*</span></div>
          <div class="right">
            <select name="map_store_name" required>
              <option value="">Select...</option>
              <?php $first=$preview[0]??[]; foreach($first as $i=>$n): ?>
                <option value="<?php echo (int)$i; ?>"><?php echo htmlspecialchars($n!==''?$n:('Column '.($i+1)), ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="left">Cod</div>
          <div class="right">
            <select name="map_cod">
              <option value="-1">Skip</option>
              <?php $first=$preview[0]??[]; foreach($first as $i=>$n): ?>
                <option value="<?php echo (int)$i; ?>"><?php echo htmlspecialchars($n!==''?$n:('Column '.($i+1)), ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="left">Contact Name</div>
          <div class="right">
            <select name="map_contact_name">
              <option value="-1">Skip</option>
              <?php $first=$preview[0]??[]; foreach($first as $i=>$n): ?>
                <option value="<?php echo (int)$i; ?>"><?php echo htmlspecialchars($n!==''?$n:('Column '.($i+1)), ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="left">Website</div>
          <div class="right">
            <select name="map_website">
              <option value="-1">Skip</option>
              <?php $first=$preview[0]??[]; foreach($first as $i=>$n): ?>
                <option value="<?php echo (int)$i; ?>"><?php echo htmlspecialchars($n!==''?$n:('Column '.($i+1)), ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="left">Phone DDD</div>
          <div class="right">
            <select name="map_phone_ddd">
              <option value="-1">Skip</option>
              <?php $first=$preview[0]??[]; foreach($first as $i=>$n): ?>
                <option value="<?php echo (int)$i; ?>"><?php echo htmlspecialchars($n!==''?$n:('Column '.($i+1)), ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="left">Phone</div>
          <div class="right">
            <select name="map_phone">
              <option value="-1">Skip</option>
              <?php $first=$preview[0]??[]; foreach($first as $i=>$n): ?>
                <option value="<?php echo (int)$i; ?>"><?php echo htmlspecialchars($n!==''?$n:('Column '.($i+1)), ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="left">Email</div>
          <div class="right">
            <select name="map_email">
              <option value="-1">Skip</option>
              <?php $first=$preview[0]??[]; foreach($first as $i=>$n): ?>
                <option value="<?php echo (int)$i; ?>"><?php echo htmlspecialchars($n!==''?$n:('Column '.($i+1)), ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="left">Address Line 1</div>
          <div class="right">
            <select name="map_address_line1">
              <option value="-1">Skip</option>
              <?php $first=$preview[0]??[]; foreach($first as $i=>$n): ?>
                <option value="<?php echo (int)$i; ?>"><?php echo htmlspecialchars($n!==''?$n:('Column '.($i+1)), ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="left">City</div>
          <div class="right">
            <select name="map_city">
              <option value="-1">Skip</option>
              <?php $first=$preview[0]??[]; foreach($first as $i=>$n): ?>
                <option value="<?php echo (int)$i; ?>"><?php echo htmlspecialchars($n!==''?$n:('Column '.($i+1)), ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="left">State</div>
          <div class="right">
            <select name="map_state">
              <option value="-1">Skip</option>
              <?php $first=$preview[0]??[]; foreach($first as $i=>$n): ?>
                <option value="<?php echo (int)$i; ?>"><?php echo htmlspecialchars($n!==''?$n:('Column '.($i+1)), ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="left">Zip Code</div>
          <div class="right">
            <select name="map_zip_code">
              <option value="-1">Skip</option>
              <?php $first=$preview[0]??[]; foreach($first as $i=>$n): ?>
                <option value="<?php echo (int)$i; ?>"><?php echo htmlspecialchars($n!==''?$n:('Column '.($i+1)), ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="col-12">
          <button class="btn" type="submit">Import</button>
        </div>
      </form>
      <?php endif; ?>
    </section>
  </div>
</body>
</html>

