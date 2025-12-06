<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

// Minimal XLSX reader (first worksheet only) for mapping/import
function xlsx_read_shared_strings($zip) {
  $out = [];
  $xml = $zip->getFromName('xl/sharedStrings.xml');
  if ($xml === false) {
    error_log("DEBUG: sharedStrings.xml not found or empty");
    return $out;
  }
  // extract <si>...</si> text
  if (preg_match_all('/<si>(.*?)<\/si>/s', $xml, $m)) {
    foreach ($m[1] as $si) {
      // concatenate all <t> pieces
      $txt = '';
      if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $t)) {
        foreach ($t[1] as $piece) { $txt .= html_entity_decode(strip_tags($piece), ENT_QUOTES | ENT_XML1, 'UTF-8'); }
      }
      $out[] = $txt;
    }
    error_log("DEBUG: Read " . count($out) . " shared strings, first 5: " . json_encode(array_slice($out, 0, 5), JSON_UNESCAPED_UNICODE));
  } else {
    error_log("DEBUG: No <si> tags found in sharedStrings.xml");
  }
  return $out;
}

function xlsx_col_to_index($cellRef) {
  // e.g., A, B, AA -> 0-based index
  if (!preg_match('/([A-Z]+)[0-9]+/i', $cellRef, $m)) return 0;
  $col = strtoupper($m[1]);
  $n = 0;
  for ($i=0; $i<strlen($col); $i++) { $n = $n*26 + (ord($col[$i]) - 64); }
  return $n - 1;
}

function xlsx_list_sheets($path) {
  $out = [];
  $zip = new ZipArchive();
  if ($zip->open($path) !== true) return $out;
  $wb = $zip->getFromName('xl/workbook.xml');
  $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
  $map = [];
  if ($rels !== false && preg_match_all('/<Relationship[^>]*Id="(rId\d+)"[^>]*Target="([^"]+)"/i', $rels, $rm, PREG_SET_ORDER)) {
    foreach ($rm as $r) { $map[$r[1]] = 'xl/' . ltrim($r[2], '/'); }
  }
  if ($wb !== false && preg_match_all('/<sheet[^>]*name="([^"]+)"[^>]*r:id="(rId\d+)"/i', $wb, $sm, PREG_SET_ORDER)) {
    foreach ($sm as $s) {
      $name = html_entity_decode($s[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
      $rid = $s[2];
      $path = isset($map[$rid]) ? $map[$rid] : 'xl/worksheets/sheet1.xml';
      $out[] = ['name'=>$name, 'path'=>$path];
    }
  }
  $zip->close();
  return $out;
}

function read_xlsx_rows($path, $maxRows = 0, $sheetPath = 'xl/worksheets/sheet1.xml') {
  $rows = [];
  $zip = new ZipArchive();
  if ($zip->open($path) !== true) return $rows;
  $sheet = $zip->getFromName($sheetPath);
  if ($sheet === false) { $zip->close(); return $rows; }
  $shared = xlsx_read_shared_strings($zip);
  $zip->close();
  // parse rows - simplified like import_sections.php
  if (preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheet, $rm)) {
    $count = 0;
    foreach ($rm[1] as $rxml) {
      $row = [];
      if (preg_match_all('/<c([^>]*)>(.*?)<\/c>/s', $rxml, $cm, PREG_SET_ORDER)) {
        foreach ($cm as $c) {
          $attrs = $c[1];
          $cbody = $c[2];
          if (!preg_match('/r="([A-Z0-9]+)"/i', $attrs, $refm)) continue;
          $colIdx = xlsx_col_to_index($refm[1]);
          $type = '';
          if (preg_match('/\bt="(\w+)"/i', $attrs, $tm)) $type = strtolower($tm[1]);
          $val = '';
          if ($type === 's') {
            if (preg_match('/<v>(\d+)<\/v>/', $cbody, $vm)) {
              $sid = (int)$vm[1];
              $val = isset($shared[$sid]) ? $shared[$sid] : '';
            }
          } elseif ($type === 'inlinestr') {
            if (preg_match('/<t[^>]*>(.*?)<\/t>/s', $cbody, $tm2)) {
              $val = html_entity_decode($tm2[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
          } else {
            if (preg_match('/<v>(.*?)<\/v>/', $cbody, $vm)) {
              $val = (string)$vm[1]; // Preserve as string for numbers
            }
          }
          $row[$colIdx] = $val;
        }
      }
      if (!empty($row)) { ksort($row); }
      $rows[] = $row;
      $count++;
      if ($maxRows > 0 && $count >= $maxRows) break;
    }
  }
  return $rows;
}
$arquivoConfig = __DIR__ . '/config.php';
if (!file_exists($arquivoConfig)) { die('config.php not found'); }
require_once $arquivoConfig;

$csrf_name = '_csrf';
if (empty($_SESSION[$csrf_name])) { $_SESSION[$csrf_name] = bin2hex(random_bytes(16)); }
$csrf_token = $_SESSION[$csrf_name];

$company_id = 1;
$erro = '';
$ok = '';
$warning = '';
$skipped_lines = [];
if (!empty($_SESSION['import_ok'])) { $ok = (string)$_SESSION['import_ok']; unset($_SESSION['import_ok']); }
if (!empty($_SESSION['import_warning'])) { $warning = (string)$_SESSION['import_warning']; unset($_SESSION['import_warning']); }
if (!empty($_SESSION['skipped_lines'])) { $skipped_lines = $_SESSION['skipped_lines']; unset($_SESSION['skipped_lines']); }

function detect_delimiter($path) {
  $h = fopen($path, 'r');
  if (!$h) return ',';
  $line = fgets($h);
  fclose($h);
  if ($line === false) return ',';
  $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), '\t' => substr_count($line, "\t")];
  arsort($counts);
  return array_key_first($counts);
}

function read_csv_sample($path, $delimiter, $limit = 2000) {
  $rows = [];
  if (($h = fopen($path, 'r')) !== false) {
    $i = 0;
    while (($data = fgetcsv($h, 0, $delimiter)) !== false && $i < $limit) { $rows[] = $data; $i++; }
    fclose($h);
  }
  return $rows;
}

$step = isset($_POST['step']) ? (string)$_POST['step'] : 'upload';
$tmpfile = isset($_POST['tmpfile']) ? (string)$_POST['tmpfile'] : '';
$filetype = isset($_POST['filetype']) ? (string)$_POST['filetype'] : '';// csv|xlsx
$sheetpath = isset($_POST['sheetpath']) ? (string)$_POST['sheetpath'] : '';
$has_header = isset($_POST['has_header']) && $_POST['has_header'] === '1';

// Upload step: accept .csv (Excel-exported) files
if ($step === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST[$csrf_name]) || !hash_equals($_SESSION[$csrf_name], (string)$_POST[$csrf_name])) {
    $erro = 'Session expired. Please reload the page.';
  } else {
    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
      $erro = 'Please select a CSV or XLSX file.';
    } else {
      $ext = strtolower(pathinfo($_FILES['csv']['name'], PATHINFO_EXTENSION));
      if ($ext !== 'csv' && $ext !== 'xlsx') { $erro = 'Only CSV or XLSX files are supported.'; }
      if (!$erro) {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'import_products_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['csv']['tmp_name'], $tmp)) { $erro = 'Failed to store uploaded file.'; }
        else { $tmpfile = $tmp; $filetype = $ext; $step = 'map'; }
      }
    }
  }
}

// Map step: show dropdowns to map csv columns to system fields
$preview = [];
$delimiter = ',';
if ($step === 'map' && $tmpfile && file_exists($tmpfile)) {
  $ext = strtolower(pathinfo($tmpfile, PATHINFO_EXTENSION));
  if ($ext === 'xlsx') {
    // pick first sheet (or provided)
    $sheets = xlsx_list_sheets($tmpfile);
    if (!$sheetpath && !empty($sheets)) { $sheetpath = $sheets[0]['path']; }
    $preview = read_xlsx_rows($tmpfile, 15, $sheetpath ?: 'xl/worksheets/sheet1.xml');
  } else {
    $delimiter = detect_delimiter($tmpfile);
    $preview = read_csv_sample($tmpfile, $delimiter, 15);
  }
  // Don't normalize preview for mapping - use raw values like import_sections.php
  // Only normalize during actual import processing
}

// Process step: perform validations and insert rows
if ($step === 'process' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST[$csrf_name]) || !hash_equals($_SESSION[$csrf_name], (string)$_POST[$csrf_name])) {
    $erro = 'Session expired. Please reload the page.';
  } else {
    $tmpfile = isset($_POST['tmpfile']) ? (string)$_POST['tmpfile'] : '';
    $filetype = isset($_POST['filetype']) ? (string)$_POST['filetype'] : '';
    $sheetpath = isset($_POST['sheetpath']) ? (string)$_POST['sheetpath'] : '';
    $has_header = isset($_POST['has_header']) && $_POST['has_header'] === '1';
    $delimiter = isset($_POST['delimiter']) ? (string)$_POST['delimiter'] : ',';
    $map = [
      'cod' => (int)($_POST['map_cod'] ?? -1),
      'manufacturer_name' => (int)($_POST['map_manufacturer'] ?? -1),
      'section_name' => (int)($_POST['map_section'] ?? -1),
      'description' => (int)($_POST['map_description'] ?? -1),
      'unit' => (int)($_POST['map_unit'] ?? -1),
      'box_qty' => (int)($_POST['map_box'] ?? -1),
    ];
    foreach (['cod','manufacturer_name','section_name','description','unit','box_qty'] as $k) {
      if ($map[$k] < 0) { $erro = 'Please map all required fields.'; break; }
    }
    if (!$erro && (!file_exists($tmpfile))) { $erro = 'Temporary file not found. Please upload again.'; }
    
    // Validate that map indices are reasonable (not negative and within expected range)
    if (!$erro) {
      foreach ($map as $k => $idx) {
        if ($idx < 0) {
          $erro = 'Invalid mapping for field: ' . $k;
          break;
        }
      }
    }

    if (!$erro) {
      // Preload manufacturers and sections by name -> id
      $manu = [];
      if ($stmt = $conn->prepare('SELECT id, description FROM manufacturers WHERE company_id=?')) {
        $stmt->bind_param('i', $company_id);
        if ($stmt->execute()) { $res=$stmt->get_result(); while($r=$res->fetch_assoc()){ $manu[mb_strtoupper(trim($r['description']))] = (int)$r['id']; } $res->free(); }
        $stmt->close();
      }
      $secs = [];
      if ($stmt = $conn->prepare('SELECT id, description FROM sections WHERE company_id=?')) {
        $stmt->bind_param('i', $company_id);
        if ($stmt->execute()) { $res=$stmt->get_result(); while($r=$res->fetch_assoc()){ $secs[mb_strtoupper(trim($r['description']))] = (int)$r['id']; } $res->free(); }
        $stmt->close();
      }

      $missing_manu = [];
      $missing_sec = [];
      $to_insert = [];
      $skipped_rows = 0;
      $skipped_lines_data = [];

      if (strtolower(pathinfo($tmpfile, PATHINFO_EXTENSION)) === 'xlsx') {
        $all = read_xlsx_rows($tmpfile, 0, $sheetpath ?: 'xl/worksheets/sheet1.xml');
        $start = $has_header ? 1 : 0;
        // Get max column index from all rows (same logic as preview normalization)
        $maxCol = 0;
        foreach ($all as $row) {
          foreach ($row as $idx => $val) {
            if ($idx > $maxCol) $maxCol = $idx;
          }
        }
        // Ensure at least max mapped index is covered
        $maxMapped = max(array_values($map));
        if ($maxMapped > $maxCol) $maxCol = $maxMapped;
        for ($rownum = $start; $rownum < count($all); $rownum++) {
          $data = $all[$rownum];
          // Normalize data array: fill missing indices with empty strings up to max column
          for ($i = 0; $i <= $maxCol; $i++) {
            if (!isset($data[$i])) {
              $data[$i] = '';
            }
          }
          ksort($data);
          // Reindex to 0..n to match preview indexing
          $data = array_values($data);
          
          // Debug: log raw data before processing for first few rows
          if ($rownum < 3) {
            error_log("DEBUG XLSX Row " . ($rownum + 1) . " BEFORE normalization: map_cod=" . $map['cod'] . ", data_before=" . json_encode($all[$rownum]));
            error_log("DEBUG XLSX Row " . ($rownum + 1) . " AFTER normalization: data_count=" . count($data) . ", data=" . json_encode(array_slice($data, 0, 10)));
          }
          
          $get = function($idx) use ($data){ 
            if (!isset($data[$idx])) return '';
            $val = $data[$idx];
            // Always convert to string to preserve special characters and prevent number conversion
            // Use mb_convert_encoding if available to ensure UTF-8
            if (is_numeric($val)) {
              // If it's a number, convert to string preserving the exact value (including leading zeros if any)
              // Use number_format to preserve decimal places, then convert to string
              $val = (string)$val;
            } else {
              $val = (string)$val;
              // Ensure UTF-8 encoding for special characters (accents, etc.)
              if (function_exists('mb_convert_encoding')) {
                $val = mb_convert_encoding($val, 'UTF-8', 'UTF-8');
              }
            }
            $result = trim($val);
            // Return empty string if result is empty after trim, but preserve '0' as valid value
            return $result === '' ? '' : $result;
          };
          // Validate map index is within data range for COD - use defensive approach
          $cod = '';
          if ($map['cod'] >= 0 && $map['cod'] < count($data)) {
            // Get the value using the mapped index
            $cod = $get($map['cod']);
            // Debug for first few rows
            if ($rownum < 3) {
              error_log("DEBUG COD XLSX Row " . ($rownum + 1) . ": map_index=" . $map['cod'] . ", cod_value='" . $cod . "', data_at_index=" . (isset($data[$map['cod']]) ? "'" . $data[$map['cod']] . "'" : "NOT SET"));
            }
            // Ensure COD is never null - use empty string if missing, but preserve '0' as valid
            if ($cod === null || $cod === false) $cod = '';
            // Don't trim again - already trimmed in $get function, but ensure it's a string
            $cod = (string)$cod;
          } else if ($map['cod'] >= 0 && isset($data[$map['cod']])) {
            // Fallback: try direct access if normalization didn't work as expected
            $raw_val = $data[$map['cod']];
            $cod = is_numeric($raw_val) ? (string)$raw_val : trim((string)$raw_val);
            if ($rownum < 3) {
              error_log("DEBUG COD XLSX Row " . ($rownum + 1) . " FALLBACK: map_index=" . $map['cod'] . ", cod_value='" . $cod . "'");
            }
          } else {
            if ($rownum < 3) {
              error_log("DEBUG COD XLSX Row " . ($rownum + 1) . " ERROR: map_index=" . $map['cod'] . " out of range, data_count=" . count($data));
            }
          }
          $manufacturer_name = $get($map['manufacturer_name']);
          $section_name = $get($map['section_name']);
          $description = $get($map['description']);
          $unit = $get($map['unit']);
          $box_qty = (int)preg_replace('/[^0-9-]/','', $get($map['box_qty']));
          if ($box_qty <= 0) $box_qty = 1; // default 1
          $real_value = 0.00; // default per request

          $mkey = mb_strtoupper(trim($manufacturer_name));
          $skey = mb_strtoupper(trim($section_name));
          $mid = $manu[$mkey] ?? 0;
          $sid = $secs[$skey] ?? 0;
          
          // Skip rows with missing manufacturer or section - only insert if both are valid (> 0)
          if ($mid <= 0 || $sid <= 0) {
            $reasons = [];
            if ($mid <= 0) {
              if ($mkey !== '') {
                if (!isset($missing_manu[$mkey])) { $missing_manu[$mkey] = 0; }
                $missing_manu[$mkey]++;
                $reasons[] = 'Manufacturer not found: ' . $manufacturer_name;
              } else {
                $reasons[] = 'Manufacturer is empty';
              }
            }
            if ($sid <= 0) {
              if ($skey !== '') {
                if (!isset($missing_sec[$skey])) { $missing_sec[$skey] = 0; }
                $missing_sec[$skey]++;
                $reasons[] = 'Section not found: ' . $section_name;
              } else {
                $reasons[] = 'Section is empty';
              }
            }
            $skipped_rows++;
            $line_num = $has_header ? $rownum : $rownum + 1;
            $skipped_lines_data[] = [
              'line' => $line_num,
              'cod' => $cod,
              'manufacturer' => $manufacturer_name,
              'section' => $section_name,
              'description' => $description,
              'unit' => $unit,
              'box_qty' => $box_qty,
              'reason' => !empty($reasons) ? implode(' | ', $reasons) : 'Unknown reason'
            ];
            continue;
          }
          
          // Calculate unit_value: real_value / box_qty (if box_qty > 0), default to 0.00
          $unit_value = 0.00;
          if ($box_qty > 0 && $real_value > 0) {
            $unit_value = $real_value / $box_qty;
            // Truncate to 2 decimals (same as products.php)
            $unit_value = floor($unit_value * 100) / 100;
          }
          
          // Only add to insert if both manufacturer_id and section_id are valid
          // Ensure COD is preserved as string (even if empty, don't convert to number)
          $cod_final = (string)$cod; // Force string type to preserve value
          // Debug: log first few COD values to help diagnose (XLSX)
          if (count($to_insert) < 3) {
            error_log("DEBUG COD XLSX - Row " . ($rownum + 1) . ": map_index=" . $map['cod'] . ", cod_value='" . $cod_final . "', data_count=" . count($data) . ", raw_data=" . json_encode(array_slice($data, 0, 5)));
          }
          $to_insert[] = [
            'cod'=>$cod_final, 'manufacturer_id'=>$mid, 'section_id'=>$sid,
            'description'=>$description, 'unit'=>$unit,
            'box_qty'=>$box_qty, 'real_value'=>$real_value,
            'unit_value'=>$unit_value,
            // defaults
            'is_great_deal'=>0, 'is_active'=>1
          ];
        }
      } else if (($h = fopen($tmpfile, 'r')) !== false) {
        // Ensure UTF-8 encoding for CSV reading
        if (function_exists('mb_convert_encoding')) {
          // Try to detect and convert encoding if needed
          $first_line = fgets($h);
          rewind($h);
        }
        $rownum = 0;
        while (($data = fgetcsv($h, 0, $delimiter)) !== false) {
          $rownum++;
          if ($has_header && $rownum === 1) { continue; }
          // Convert array values to UTF-8 and preserve special characters
          foreach ($data as $k => $v) {
            if (is_string($v) && function_exists('mb_convert_encoding')) {
              // Ensure UTF-8 encoding, preserve accents
              $data[$k] = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
            }
          }
          // pull and normalize
          $get = function($idx) use ($data){ 
            if (!isset($data[$idx])) return '';
            $val = (string)$data[$idx];
            // Preserve encoding and special characters (accents, etc.) - don't convert to number
            $result = trim($val);
            // Return empty string if result is empty after trim, but preserve '0' as valid value
            return $result === '' ? '' : $result;
          };
          // Validate map index is within data range for COD - use defensive approach
          $cod = '';
          if ($map['cod'] >= 0 && $map['cod'] < count($data)) {
            // Get the value using the mapped index
            $cod = $get($map['cod']);
            // Ensure COD is never null - use empty string if missing, but preserve '0' as valid
            if ($cod === null || $cod === false) $cod = '';
            // Don't trim again - already trimmed in $get function, but ensure it's a string
            $cod = (string)$cod;
          } else if ($map['cod'] >= 0 && isset($data[$map['cod']])) {
            // Fallback: try direct access if normalization didn't work as expected
            $raw_val = $data[$map['cod']];
            $cod = is_numeric($raw_val) ? (string)$raw_val : trim((string)$raw_val);
          }
          $manufacturer_name = $get($map['manufacturer_name']);
          $section_name = $get($map['section_name']);
          $description = $get($map['description']);
          $unit = $get($map['unit']);
          $box_qty = (int)preg_replace('/[^0-9-]/','', $get($map['box_qty']));
          if ($box_qty <= 0) $box_qty = 1; // default 1
          $real_value = 0.00; // default per request

          $mkey = mb_strtoupper(trim($manufacturer_name));
          $skey = mb_strtoupper(trim($section_name));
          $mid = $manu[$mkey] ?? 0;
          $sid = $secs[$skey] ?? 0;
          
          // Skip rows with missing manufacturer or section - only insert if both are valid (> 0)
          if ($mid <= 0 || $sid <= 0) {
            $reasons = [];
            if ($mid <= 0) {
              if ($mkey !== '') {
                if (!isset($missing_manu[$mkey])) { $missing_manu[$mkey] = 0; }
                $missing_manu[$mkey]++;
                $reasons[] = 'Manufacturer not found: ' . $manufacturer_name;
              } else {
                $reasons[] = 'Manufacturer is empty';
              }
            }
            if ($sid <= 0) {
              if ($skey !== '') {
                if (!isset($missing_sec[$skey])) { $missing_sec[$skey] = 0; }
                $missing_sec[$skey]++;
                $reasons[] = 'Section not found: ' . $section_name;
              } else {
                $reasons[] = 'Section is empty';
              }
            }
            $skipped_rows++;
            $line_num = $has_header ? $rownum : $rownum;
            $skipped_lines_data[] = [
              'line' => $line_num,
              'cod' => $cod,
              'manufacturer' => $manufacturer_name,
              'section' => $section_name,
              'description' => $description,
              'unit' => $unit,
              'box_qty' => $box_qty,
              'reason' => !empty($reasons) ? implode(' | ', $reasons) : 'Unknown reason'
            ];
            continue;
          }
          
          // Calculate unit_value: real_value / box_qty (if box_qty > 0), default to 0.00
          $unit_value = 0.00;
          if ($box_qty > 0 && $real_value > 0) {
            $unit_value = $real_value / $box_qty;
            // Truncate to 2 decimals (same as products.php)
            $unit_value = floor($unit_value * 100) / 100;
          }
          
          // Only add to insert if both manufacturer_id and section_id are valid
          // Ensure COD is preserved as string (even if empty, don't convert to number)
          $cod_final = (string)$cod; // Force string type to preserve value
          // Debug: log first few COD values to help diagnose (CSV)
          if (count($to_insert) < 3) {
            error_log("DEBUG COD CSV - Row " . $rownum . ": map_index=" . $map['cod'] . ", cod_value='" . $cod_final . "', data_count=" . count($data) . ", raw_data=" . json_encode(array_slice($data, 0, 5)));
          }
          $to_insert[] = [
            'cod'=>$cod_final, 'manufacturer_id'=>$mid, 'section_id'=>$sid,
            'description'=>$description, 'unit'=>$unit,
            'box_qty'=>$box_qty, 'real_value'=>$real_value,
            'unit_value'=>$unit_value,
            // defaults
            'is_great_deal'=>0, 'is_active'=>1
          ];
        }
        fclose($h);
      } else {
        $erro = 'Could not open uploaded file.';
      }

      // Show warning if there are missing manufacturers/sections, but don't block import
      if (!$erro && (!empty($missing_manu) || !empty($missing_sec))) {
        $missing_manu_keys = array_filter(array_keys($missing_manu), function($k) { return $k !== ''; });
        $missing_sec_keys = array_filter(array_keys($missing_sec), function($k) { return $k !== ''; });
        $msg = [];
        if (!empty($missing_manu_keys)) {
          $manu_details = [];
          foreach ($missing_manu_keys as $k) {
            $count = $missing_manu[$k];
            $manu_details[] = $k . ($count > 1 ? ' (' . $count . ' rows)' : '');
          }
          $msg[] = 'Manufacturers not found: ' . implode(', ', $manu_details);
        }
        if (!empty($missing_sec_keys)) {
          $sec_details = [];
          foreach ($missing_sec_keys as $k) {
            $count = $missing_sec[$k];
            $sec_details[] = $k . ($count > 1 ? ' (' . $count . ' rows)' : '');
          }
          $msg[] = 'Sections not found: ' . implode(', ', $sec_details);
        }
        if ($skipped_rows > 0) {
          $msg[] = $skipped_rows . ' row(s) were skipped due to missing manufacturers/sections.';
        }
        // Store as warning, not error - import will continue
        $_SESSION['import_warning'] = implode(' | ', $msg) . '. Rows with missing data were skipped.';
        if (!empty($skipped_lines_data)) {
          $_SESSION['skipped_lines'] = $skipped_lines_data;
        }
      }

      if (!$erro && !empty($to_insert)) {
        // load existing product cods to skip duplicates
        $existing = [];
        if ($stmt = $conn->prepare('SELECT cod FROM products WHERE company_id=?')) {
          $stmt->bind_param('i', $company_id);
          if ($stmt->execute()) { $res=$stmt->get_result(); while($r=$res->fetch_assoc()){ $existing[mb_strtoupper(trim((string)$r['cod']))]=true; } $res->free(); }
          $stmt->close();
        }
        $conn->begin_transaction();
        try {
          $sql = 'INSERT INTO products (company_id, cod, manufacturer_id, section_id, description, unit, box_qty, real_value, unit_value, is_great_deal, is_active, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?, NOW(), NOW())';
          $stmt = $conn->prepare($sql);
          if (!$stmt) { throw new Exception('Prepare failed'); }
          $inserted = 0; $skipped = 0;
          foreach ($to_insert as $p) {
            // Double-check that manufacturer_id and section_id are valid before inserting
            if ($p['manufacturer_id'] <= 0 || $p['section_id'] <= 0) {
              $skipped++;
              continue;
            }
            // Ensure COD is string and preserve original value (even if empty)
            $cod_to_insert = isset($p['cod']) ? (string)$p['cod'] : '';
            // Always insert COD, even if empty - don't skip empty codes
            $key = mb_strtoupper(trim($cod_to_insert));
            // Only skip if COD is not empty AND already exists
            if ($key !== '' && isset($existing[$key])) { $skipped++; continue; }
            // Debug: log first few insertions
            if ($inserted < 3) {
              error_log("DEBUG COD INSERT - cod_to_insert='" . $cod_to_insert . "', key='" . $key . "', description='" . substr($p['description'], 0, 30) . "'");
            }
            // Bind parameters: company_id(i), cod(s), manufacturer_id(i), section_id(i), description(s), unit(s), box_qty(i), real_value(d), unit_value(d), is_great_deal(i), is_active(i)
            $stmt->bind_param('isiissiddii', $company_id, $cod_to_insert, $p['manufacturer_id'], $p['section_id'], $p['description'], $p['unit'], $p['box_qty'], $p['real_value'], $p['unit_value'], $p['is_great_deal'], $p['is_active']);
            if (!$stmt->execute()) { 
              $mysql_error = $stmt->error ? $stmt->error : ($conn->error ? $conn->error : 'Unknown error');
              throw new Exception('Insert failed: ' . $mysql_error);
            }
            if ($key !== '') { $existing[$key] = true; }
            $inserted++;
          }
          $stmt->close();
          $conn->commit();
          if ($inserted > 0) { 
            $ok_msg = 'Imported ' . $inserted . ' product(s). Duplicates by cod were skipped.';
            if ($skipped_rows > 0) {
              $ok_msg .= ' ' . $skipped_rows . ' row(s) skipped due to missing manufacturers/sections.';
            }
          }
          else { 
            if ($skipped_rows > 0) {
              $ok_msg = 'No new products to insert. ' . $skipped_rows . ' row(s) skipped due to missing manufacturers/sections.';
            } else {
              $ok_msg = 'No new products to insert. All rows were duplicates or empty cods.';
            }
          }
          // Store success message in session
          $_SESSION['import_ok'] = $ok_msg;
          // Store skipped lines in session for display
          if (!empty($skipped_lines_data)) {
            $_SESSION['skipped_lines'] = $skipped_lines_data;
          }
          // remove temp file
          @unlink($tmpfile);
          $tmpfile = '';
          $step = 'upload';
        } catch (Throwable $e) {
          $conn->rollback();
          $error_msg = $e->getMessage();
          $mysql_error = $conn->error ? $conn->error : '';
          $erro = 'Import failed. No rows were saved.';
          $erro .= '<br><strong>Error:</strong> ' . htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8');
          if ($mysql_error && $mysql_error !== $error_msg) {
            $erro .= '<br><strong>MySQL Error:</strong> ' . htmlspecialchars($mysql_error, ENT_QUOTES, 'UTF-8');
          }
          if ($e->getCode() > 0) {
            $erro .= '<br><strong>Error Code:</strong> ' . $e->getCode();
          }
          if ($e->getFile()) {
            $erro .= '<br><strong>File:</strong> ' . htmlspecialchars(basename($e->getFile()), ENT_QUOTES, 'UTF-8') . ' (line ' . $e->getLine() . ')';
          }
        }
      }
    }
  }
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Import Products</title>
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
    .col-6{grid-column: span 6}
    .col-8{grid-column: span 8}
    .muted{color:var(--muted)}
    table{width:100%; border-collapse:collapse}
    th,td{padding:6px 8px; border-bottom:1px solid rgba(148,163,184,.14)}
    .msg{margin:8px 0 0; font-size:14px}
    .error{color:#ef4444}
    .ok{color:#22c55e}
    .warning{color:#facc15}
    .skipped-table{margin-top:20px; width:100%; border-collapse:collapse}
    .skipped-table th, .skipped-table td{padding:8px 12px; text-align:left; border-bottom:1px solid rgba(148,163,184,.14); font-size:13px}
    .skipped-table th{background:rgba(255,255,255,.03); color:var(--muted); font-weight:600}
    .skipped-table tr:hover{background:rgba(255,255,255,.02)}
    .skipped-table .reason{color:#facc15; font-size:12px}
    /* Mapping rows */
    .mapgrid{display:grid; grid-template-columns: 220px 1fr; column-gap:10px; row-gap:10px}
    .mapgrid .left{display:flex; align-items:center; justify-content:flex-start; color:var(--muted)}
    .mapgrid .right select{width:100%}
  </style>
</head>
<body>
  <div class="container">
    <div><a class="btn" href="products.php">Back</a></div>
    <section class="card">
      <h2 style="margin:0 0 10px 0">Import Products</h2>
      <?php if ($erro): ?><div class="msg error"><?php echo $erro; ?></div><?php endif; ?>
      <?php if ($warning): ?><div class="msg warning"><?php echo htmlspecialchars($warning, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="msg ok"><?php echo htmlspecialchars($ok, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
      
      <?php if (!empty($skipped_lines)): ?>
      <div style="margin-top:20px">
        <h3 style="margin:0 0 12px 0; color:#facc15; font-size:16px">Skipped Rows (<?php echo count($skipped_lines); ?>)</h3>
        <p class="muted" style="margin:0 0 12px 0; font-size:13px">The following rows were skipped and can be handled manually:</p>
        <div style="overflow-x:auto">
          <table class="skipped-table">
            <thead>
              <tr>
                <th style="width:60px">Line</th>
                <th style="width:100px">COD</th>
                <th style="width:150px">Manufacturer</th>
                <th style="width:150px">Section</th>
                <th>Description</th>
                <th style="width:80px">Unit</th>
                <th style="width:80px">Box</th>
                <th>Reason</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($skipped_lines as $line): ?>
              <tr>
                <td><?php echo htmlspecialchars((string)$line['line'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($line['cod'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($line['manufacturer'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($line['section'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($line['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($line['unit'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string)$line['box_qty'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="reason"><?php echo htmlspecialchars($line['reason'], ENT_QUOTES, 'UTF-8'); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($step === 'upload'): ?>
      <form method="post" enctype="multipart/form-data" class="grid">
        <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="step" value="upload">
        <div class="col-12">
          <label class="muted">CSV/XLSX file (exported from Excel)</label>
          <input type="file" name="csv" accept=".csv,.xlsx" required>
        </div>
        <div class="col-12">
          <button class="btn" type="submit">Upload</button>
        </div>
      </form>
      <p class="muted" style="margin-top:8px">Required columns to map: cod, manufacturer name, section name, description, unit, box_qty. Defaults: real value = 0.00, great deal = No, active = Yes. Box defaults to 1 if blank.</p>
      <?php elseif ($step === 'map'): ?>
      <form method="post" class="grid">
        <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="step" value="process">
        <input type="hidden" name="tmpfile" value="<?php echo htmlspecialchars($tmpfile, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="filetype" value="<?php echo htmlspecialchars(pathinfo($tmpfile, PATHINFO_EXTENSION), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="delimiter" value="<?php echo htmlspecialchars($delimiter, ENT_QUOTES, 'UTF-8'); ?>">
        <?php
          // Simple approach like import_sections.php - use preview[0] directly
          $first = $preview[0] ?? [];
          
          // If no columns found, show error
          if (empty($first)) {
            echo '<div class="msg error">Error: Could not read columns from file. Please try uploading again.</div>';
          } else {
            // helper to find best match by header name keywords (like import_sections.php)
            $bestMatch = function(array $keywords) use ($first) {
              $score = -1; $best = '';
              foreach ($first as $i=>$n) {
                $label = mb_strtolower(trim((string)$n));
                $hit = 0;
                foreach ($keywords as $kw) { if ($kw !== '' && strpos($label, mb_strtolower($kw)) !== false) { $hit++; } }
                if ($hit > $score) { $score = $hit; $best = (string)$i; }
              }
              return $best;
            };
            echo '<div class="col-12 mapgrid">';
          if (strtolower(pathinfo($tmpfile, PATHINFO_EXTENSION)) === 'xlsx') {
            echo '<div class="left">Sheet</div><div class="right"><select name="sheetpath" required>';
            $sheets = xlsx_list_sheets($tmpfile);
            foreach ($sheets as $idx=>$s) {
              $sel = ($sheetpath && $sheetpath===$s['path']) ? ' selected' : ((!$sheetpath && $idx===0)?' selected':'');
              echo '<option value="'.htmlspecialchars($s['path'], ENT_QUOTES, 'UTF-8').'"'.$sel.'>'.htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8').'</option>';
            }
            echo '</select></div>';
          }
          echo '<div class="left">First row is header</div><div class="right"><label style="display:flex; align-items:center; gap:8px"><input type="checkbox" name="has_header" value="1" checked></label></div>';
          $renderRow = function($title, $name, $required, $preferredKeywords = []) use ($first, $bestMatch) {
            $pref = $bestMatch($preferredKeywords);
            echo '<div class="left">'.$title.'</div>';
            echo '<div class="right">';
            echo '<select name="'.$name.'" '.($required?'required':'').'>'; 
            echo '<option value="">Select...</option>';
            // Use $first directly like import_sections.php
            foreach ($first as $i=>$n) {
              $sel = ($pref !== '' && (string)$i === $pref) ? ' selected' : '';
              // Display the column name exactly as in import_sections.php
              $displayName = $n !== '' ? $n : ('Column '.($i+1));
              echo '<option value="'.(int)$i.'"'.$sel.'>'.htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8').'</option>';
            }
            echo '</select>';
            echo '</div>';
          };
          $renderRow('COD', 'map_cod', true, ['cod','codigo','código','code','cÓdigo','CÓDIGO']);
          $renderRow('Manufacturer name', 'map_manufacturer', true, ['manufacturer','fabricante','brand']);
          $renderRow('Section name', 'map_section', true, ['section','secao','categoria','category']);
          $renderRow('Description', 'map_description', true, ['description','produto','nome','name','title']);
          $renderRow('Unit', 'map_unit', true, ['unit','unidade','uom']);
          $renderRow('Box qty', 'map_box', true, ['box','box_qty','qtd','caixa','pack','package']);
            echo '</div>';
          }
        ?>
        <div class="col-12">
          <button class="btn" type="submit">Validate & Import</button>
          <a class="btn" href="import_products.php">Cancel</a>
        </div>
      </form>
      <?php endif; ?>
    </section>
  </div>
</body>
</html>
