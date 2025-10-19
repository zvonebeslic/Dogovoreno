<?php
function send_verification_email($to, $link){
  $subj = "Potvrdite registraciju — Dogovoreno";
  $msg  = "Kliknite za potvrdu:\n\n$link\n\nAko niste vi, ignorirajte poruku.";
  $hdr  = "From: Dogovoreno <no-reply@".$_SERVER['HTTP_HOST'].">\r\n".
          "Reply-To: no-reply@".$_SERVER['HTTP_HOST']."\r\n";
  return mail($to, $subj, $msg, $hdr);
}

function send_contact_email($to, $subject, $body){
  $hdr  = "From: Dogovoreno <no-reply@".$_SERVER['HTTP_HOST'].">\r\n".
          "Reply-To: no-reply@".$_SERVER['HTTP_HOST']."\r\n";
  $hdr .= "Content-Type: text/plain; charset=UTF-8\r\n";
  return mail($to, $subject, $body, $hdr);
}
