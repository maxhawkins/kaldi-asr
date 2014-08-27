<?php
// When a user asks for a file such as
//  /downloads/build/10/trunk/egs/wsj/s5/archive.tar.gz
// this will be converted by our our apache config (see the file config/kaldi-asr)
// into something of the form
//  get_archive.php?id=10/trunk/egs/wsj/s5

$id = $_GET["id"]; // e.g. 10/trunk/egs/wsj/s5
if (!defined $id) {
   syslog(LOG_WARN, "get_archive.php called without the ?id=XXX option");
   print "<html> <body> Error getting archive, expected the ?id=XXX option to be given.  </body> </html>\n";
   http_response_code(404);
   exit(0);
}
if (preg_match('#^[0-9]+/#', $id) != 1) {
   syslog(LOG_WARN, "get_archive.php?id=$id: invalid id option");
   print "<html> <body> Error getting archive, invalid id option id=$id  </body> </html>\n";
   http_response_code(404);
   exit(0);
}

$id_norm = htmlspecialchars($id);
$doc_root = $_SERVER["DOCUMENT_ROOT"];
if (!defined $doc_root) { 
  print "<html> <body> Error getting document root  </body> </html>\n";
  http_response_code(501);
  exit(0);
}
// We are assuming that $doc_root/downloads is a symlink to the disk
// (e.g. /mnt/kaldi-asr-data).  We do it like this so that we don't
// require this script to be told what the data root (e.g. /mnt/kaldi-asr-data)
// os.
$index_location = "$doc_root/downloads/build_index/$id_norm";
$build_location = "$doc_root/downloads/build/$id_norm";


// Check that we have enough server space to fulfil this request.
$file_contents = file("$index_location/size_kb");
if ($file_contents === false || count($file_contents) != 1
    || ! preg_match('/^\d+$/', $file_contents[0])) {
  syslog(LOG_ERR, "get_archive.php?id=$id: error getting size of data from $index_location/size_kb");
  print "<html> <body> Error getting archive, invalid location, id=$id  </body> </html>\n";    
  http_response_code(404);
  exit(0);
}
$size_kb = $file_contents[0];

function try_with_location($temp_disk) {
  // $temp_disk will be $doc_root/tmp.small or $doc_root/tmp.large
  // This function will return true if we fulfilled the request for the archive using 
  // this temp location, and otherwise false.
  $free_space_bytes = disk_total_space("$temp_disk");
  if ($size_kb * 1024 >= $free_space_bytes) { 
    // We may not have enough space, so don't try.
    // Note, there is a certain safety factor built in here because of the gzipping, but
    // even if this check passes, we could still fail because other jobs on the server
    // could be doing the same thing.
    return false;
  }
  // It's plausible that we could fulfil the request using this scratch space, so give
  // it a try.
  $temp_file = tempnam($temp_disk);
  if ($temp_file === false) {
    syslog(LOG_ERR, "get_archive.php?id=$id: error creating temporary file in directory $temp_disk");
    return false;
  }
  $output = system("tar czf -C $build_location $temp_file", $return_status);
  if ($return_status != 0) {
    syslog(LOG_WARN, "get_archive.php?id=$id: tar command exited with nonzero status $return_status, output was: " . substr($output, 0, 150));
    return false;
  }
  if (filesize($temp_file) == 0) {
    syslog(LOG_WARN, "tar command produced empty output.");
    return false;
  }
  if (! ($fptr = fopen($temp_file, "r"))) {
    syslog(LOG_ERR, "get_archive.php?id=$id: error opening $temp_file for reading (this should not happen)");
    if (!unlink($temp_file)) {
      syslog(LOG_ERR, "error deleting $temp_file");
    }
    return false;
  }
  if (!fpassthru($fptr)) {
    syslog(LOG_ERR, "get_archive.php?id=$id, error status returned from fpassthru, file is $temp_file");
    return false;
  }
  // It succeeded.
  if (!unlink($temp_file)) {
    syslog(LOG_ERR, "error deleting $temp_file");
  }
  return true;
}


// We need to confirm that we have enough free space to do what we want to do.
// We have two scratch spaces, one local at $doc_root/tmp.small, and one
// remote at $doc_root/tmp.large.

if (try_with_location("$doc_root/tmp.small")) {
  exit(0);
}
if (try_with_location("$doc_root/tmp.large")) {
  exit(0);
}
if (try_with_location("$doc_root/tmp.large")) {
  exit(0);
}
// After two tries, we give up.
syslog(LOG_ERR, "get_archive.php?id=$id: giving up after two tries with large temp-space.");
http_response_code(502);
print "<html> <body> Could not fulfil your request (perhaps not enough disk space on server).  Contact dpovey@gmail.com  </body> </html>\n";    
exit(0);


if ($free_space_bytes === false) {
  syslog(LOG_ERR, "get_archive.php: error getting free space on server");
  print "<html> <body> Server error (error finding amount of free space)  </body> </html>\n";    
  http_response_code(502);
  exit(0);
}

?>