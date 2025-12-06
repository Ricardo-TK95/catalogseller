<?php
// Minimal Gmail SMTP sender without Composer/PHPMailer.
// Warning: This is a basic implementation for plain-text emails only.
// Uses AUTH LOGIN and SSL (465) or STARTTLS (587).

if (!function_exists('smtp_send_gmail_ssl465')) {
  function smtp_send_gmail_ssl465($user, $pass, $from, $fromName, $to, $toName, $subject, $bodyText) {
    $host = 'smtp.gmail.com';
    $port = 465;
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 20);
    if (!$fp) return false;
    stream_set_timeout($fp, 20);

    $read = function() use ($fp) { return fgets($fp, 2048); };
    $write = function($data) use ($fp) { fwrite($fp, $data); };

    $expect = function($prefixes) use ($read) {
      $line = '';
      do {
        $line = $read();
        if ($line === false) return false;
      } while (isset($line[3]) && $line[3] === '-');
      foreach ((array)$prefixes as $p) { if (strpos($line, $p) === 0) return true; }
      return false;
    };

    if (!$expect('220')) { fclose($fp); return false; }
    $write("EHLO localhost\r\n");
    if (!$expect('250')) { fclose($fp); return false; }

    $write("AUTH LOGIN\r\n");
    if (!$expect('334')) { fclose($fp); return false; }
    $write(base64_encode($user)."\r\n");
    if (!$expect('334')) { fclose($fp); return false; }
    $write(base64_encode($pass)."\r\n");
    if (!$expect('235')) { fclose($fp); return false; }

    $fromAddr = format_email_address($from, $fromName);
    $toAddr   = format_email_address($to, $toName);

    $write("MAIL FROM:<{$from}>\r\n"); if (!$expect('250')) { fclose($fp); return false; }
    $write("RCPT TO:<{$to}>\r\n"); if (!$expect('250')) { fclose($fp); return false; }
    $write("DATA\r\n"); if (!$expect('354')) { fclose($fp); return false; }

    $headers = [];
    $headers[] = 'Date: '.date('r');
    $headers[] = 'From: '.$fromAddr;
    $headers[] = 'To: '.$toAddr;
    $headers[] = 'Subject: '.encode_mime_subject($subject);
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';

    $body = dot_stuff(rtrim($bodyText)."\r\n");

    $data = implode("\r\n", $headers)."\r\n\r\n".$body."\r\n.\r\n";
    $write($data);
    if (!$expect('250')) { fclose($fp); return false; }

    $write("QUIT\r\n");
    fclose($fp);
    return true;
  }
}

if (!function_exists('encode_mime_subject')) {
  function encode_mime_subject($subject) {
    $s = (string)$subject;
    // Encode as RFC 2047 Base64 UTF-8
    $b64 = base64_encode($s);
    return '=?UTF-8?B?'.$b64.'?=';
  }
}

if (!function_exists('smtp_send_gmail_plain')) {
  function smtp_send_gmail_plain($user, $pass, $from, $fromName, $to, $toName, $subject, $bodyText) {
    // STARTTLS on 587
    $host = 'smtp.gmail.com';
    $port = 587;
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 20);
    if (!$fp) return false;
    stream_set_timeout($fp, 20);

    $read = function() use ($fp) { return fgets($fp, 2048); };
    $write = function($data) use ($fp) { fwrite($fp, $data); };
    $expect = function($prefixes) use ($read) {
      $line = '';
      do { $line = $read(); if ($line === false) return false; }
      while (isset($line[3]) && $line[3] === '-');
      foreach ((array)$prefixes as $p) { if (strpos($line, $p) === 0) return true; }
      return false;
    };

    if (!$expect('220')) { fclose($fp); return false; }
    $write("EHLO localhost\r\n"); if (!$expect('250')) { fclose($fp); return false; }

    $write("STARTTLS\r\n"); if (!$expect('220')) { fclose($fp); return false; }
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }

    $write("EHLO localhost\r\n"); if (!$expect('250')) { fclose($fp); return false; }

    $write("AUTH LOGIN\r\n"); if (!$expect('334')) { fclose($fp); return false; }
    $write(base64_encode($user)."\r\n"); if (!$expect('334')) { fclose($fp); return false; }
    $write(base64_encode($pass)."\r\n"); if (!$expect('235')) { fclose($fp); return false; }

    $fromAddr = format_email_address($from, $fromName);
    $toAddr   = format_email_address($to, $toName);

    $write("MAIL FROM:<{$from}>\r\n"); if (!$expect('250')) { fclose($fp); return false; }
    $write("RCPT TO:<{$to}>\r\n"); if (!$expect('250')) { fclose($fp); return false; }
    $write("DATA\r\n"); if (!$expect('354')) { fclose($fp); return false; }

    $headers = [];
    $headers[] = 'Date: '.date('r');
    $headers[] = 'From: '.$fromAddr;
    $headers[] = 'To: '.$toAddr;
    $headers[] = 'Subject: '.encode_mime_subject($subject);
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';

    $body = dot_stuff(rtrim($bodyText)."\r\n");

    $data = implode("\r\n", $headers)."\r\n\r\n".$body."\r\n.\r\n";
    $write($data);
    if (!$expect('250')) { fclose($fp); return false; }

    $write("QUIT\r\n");
    fclose($fp);
    return true;
  }
}

if (!function_exists('format_email_address')) {
  function format_email_address($email, $name = '') {
    $email = trim((string)$email);
    $name = trim((string)$name);
    if ($name === '') return $email;
    $qname = '"'.str_replace('"','\"', $name).'"';
    return $qname.' <'.$email.'>';
  }
}

if (!function_exists('dot_stuff')) {
  function dot_stuff($text) {
    // Per RFC 5321: lines beginning with a dot must be prefixed with another dot
    $text = str_replace(["\r\n"], ["\n"], $text);
    $lines = explode("\n", $text);
    foreach ($lines as &$l) {
      if (isset($l[0]) && $l[0] === '.') $l = '.'.$l;
    }
    return implode("\r\n", $lines);
  }
}
