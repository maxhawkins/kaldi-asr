<?php
// When a user asks for a file such as
//  /downloads/build/10/trunk/egs/wsj/s5/archive.tar.gz
// this will be converted by our our apache config (see the file config/kaldi-asr)
// into something of the form
//  get_archive.php?id=10/trunk/egs/wsj/s5

if (!isset($_GET["id"])) {
   syslog(LOG_WARNING, "get_archive.php called without the ?id=XXX option");
   print "<html> <body> Error getting archive, expected the ?id=XXX option to be given.  </body> </html>\n";
   http_response_code(404);
   exit(0);
} else {
  $id = $_GET["id"]; // e.g. 10/trunk/egs/wsj/s5
}

if (preg_match('#^[0-9]+/#', $id) != 1) {
   syslog(LOG_WARNING, "get_archive.php?id=$id: invalid id option");
   print "<html> <body> Error getting archive, invalid id option id=$id  </body> </html>\n";
   http_response_code(404);
   exit(0);
}

$id_norm = htmlspecialchars($id);
$doc_root = $_SERVER["DOCUMENT_ROOT"];
if (!isset($doc_root)) { 
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

function cleanup_location($temp_disk) {
  global $id;
  $list = scandir($temp_disk);
  if ($list === false) { return false; }
  $wait_seconds = 60; // After a minute of not being accessed or modified,
                      // we assume that the file is an orphan.
  foreach ($list as $entry) {
    $path = "$temp_disk/$entry";
    if (is_file ($path)) {
      $access_interval = time() - fileatime($path);
      $modify_interval = time() - filemtime($path);
      if ($access_interval > $wait_seconds && $modify_interval > $wait_seconds) {
        syslog(LOG_WARNING, "get_archive.php?id=$id: found what appears to be orphan file $path: has not been (accessed,modified) for ($access_interval,$modify_interval) seconds.  Deleting it.");
        if (unlink($path)) {
          syslog(LOG_ERR, "get_archive.php?id=$id: error unlinking $path");
        }
      }
    }
  } 
  return true;
}

function create_temp_archive($temp_disk) {
  global $size_kb, $build_location, $id;

  // $temp_disk will be $doc_root/tmp.small or $doc_root/tmp.large
  // This function will return true if we fulfilled the request for the archive using 
  // this temp location, and otherwise false.
  $free_space_bytes = disk_free_space("$temp_disk");
  if ($size_kb * 1024 >= $free_space_bytes) { 
    // We may not have enough space, so don't try.
    // Note, there is a certain safety factor built in here because of the gzipping, but
    // even if this check passes, we could still fail because other jobs on the server
    // could be doing the same thing.
    return false;
  }
  // It's plausible that we could fulfil the request using this scratch space, so give
  // it a try.
  $temp_file = tempnam($temp_disk, "tmp");
  if ($temp_file === false) {
    syslog(LOG_ERR, "get_archive.php?id=$id: error creating temporary file in directory $temp_disk");
    return false;
  } elseif (preg_match('#^/tmp#', $temp_file) > 0) {
    syslog(LOG_ERR, "get_archive.php?id=$id: error creating temporary file in directory $temp_disk, got answer in $temp_file (permission issue?)");  
    return false;
  } else {
    syslog(LOG_INFO, "get_archive.php?id=$id: created temporary file in $temp_file, temp_disk=$temp_disk");
  }
  $output = system("tar czf $temp_file -C $build_location .", $return_status);
  if ($return_status != 0) {
    syslog(LOG_ERR, "get_archive.php?id=$id: tar command exited with nonzero status $return_status, output was: " . substr($output, 0, 150));
    if (!unlink($temp_file)) {
      syslog(LOG_ERR, "get_archive.php?id=$id: error deleting $temp_file");
    }
    return false;
  }
  $size_bytes = filesize($temp_file);
  if ($size_bytes === false || $size_bytes == 0) {
    syslog(LOG_WARNING, "get_archive.php?id=$id: tar command produced empty or no output.");
    return false;
  }
  return $temp_file;
}

function try_with_location($archive_loc, $temp_disk) {
  global $size_kb, $build_location, $id;

  if (! file_exists($archive_loc) )    {
    $file_lock_name = $archive_loc . ".lock";
    $file_lockd = fopen($file_lock_name, "w");
    if (! $file_lockd ) {
      syslog(LOG_ERR, "get_archive.php?id=$id: error opening $file_lock_name");
      return false;
    }
    if (flock($file_lockd, LOCK_EX )) {
      if (file_exists($archive_loc) ){
        // This would mean that we were competing for the lock
        // and someone and the guy that held the lock before us
        // already created the archiv.
        // To make things easy, we will return fail here,
        // The upper script will call us again (albeit with different
        // temp_disc location -- but that won't matter anyway,
        // as we will proceed directly to streaming the archive
        // we will need to take care of unlocking and cleaning...
        
        if (!flock($file_lockd, LOCK_UN)) {
          syslog(LOG_ERR, "get_archive.php?id=$id: error unlocking $file_lock_name");
        }
        if (!fclose($file_lockd)) {
          syslog(LOG_ERR, "get_archive.php?id=$id: error closing $file_lock_name");
        }
        if (!unlink($file_lock_name)) {
          syslog(LOG_ERR, "get_archive.php?id=$id: error deleting $file_lock_name");
        }
        return false;
      }

      $temp_file = create_temp_archive($temp_disk);
      if ( ! $temp_file ) {
        syslog(LOG_ERR, "get_archive_php?id=$id: error preparing the archive $temp_file into $archive_loc ");
        if (!flock($file_lockd, LOCK_UN)) {
          syslog(LOG_ERR, "get_archive.php?id=$id: error unlocking $file_lock_name");
        }
        if (!fclose($file_lockd)) {
          syslog(LOG_ERR, "get_archive.php?id=$id: error closing $file_lock_name");
        }
        if (!unlink($file_lock_name)) {
          syslog(LOG_ERR, "get_archive.php?id=$id: error deleting $file_lock_name");
        }
        return false;
      }
      if ( ! rename($temp_file, $archive_loc) ) {
        syslog(LOG_ERR, "get_archive_php?id=$id: error moving the $temp_file into $archive_loc ");
        if (!unlink($temp_file)) {
          syslog(LOG_ERR, "get_archive.php?id=$id: error deleting $temp_file");
        }
        if (!flock($file_lockd, LOCK_UN)) {
          syslog(LOG_ERR, "get_archive.php?id=$id: error unlocking $file_lock_name");
        }
        if (!fclose($file_lockd)) {
          syslog(LOG_ERR, "get_archive.php?id=$id: error closing $file_lock_name");
        }
        if (!unlink($file_lock_name)) {
          syslog(LOG_ERR, "get_archive.php?id=$id: error deleting $file_lock_name");
        }
        return false;
      }
      if (!flock($file_lockd, LOCK_UN)) {
        syslog(LOG_ERR, "get_archive.php?id=$id: error unlocking $file_lock_name");
      }
      if (!fclose($file_lockd)) {
        syslog(LOG_ERR, "get_archive.php?id=$id: error closing $file_lock_name");
      }
      if (!unlink($file_lock_name)) {
        syslog(LOG_ERR, "get_archive.php?id=$id: error deleting $file_lock_name");
      }
    }
  }
 
  $size_bytes = filesize($archive_loc);
  if ($size_bytes === false || $size_bytes == 0) {
    syslog(LOG_WARNING, "get_archive.php?id=$id: archive $archive_loc is empty?");
    return false;
  }
  if (! ($fptr = fopen($archive_loc, "r"))) {
    syslog(LOG_ERR, "get_archive.php?id=$id: error opening $archive_loc for reading (this should not happen)");
    return false;
  }
  header('Content-Type: application/octet-stream');
  header('Content-Disposition: attachment; filename="archive.tar.gz"');
  header("Content-Length: $size_bytes");

  if (!fpassthru($fptr)) {
    syslog(LOG_ERR, "get_archive.php?id=$id, error status returned from fpassthru, file is $archive_loc");
    return false;
  }
  // It succeeded.
  if (!fclose($fptr)) {
    syslog(LOG_ERR, "error closing file $archive_loc");
  }
  return true;
}


$archive_file="$doc_root/archive/$id.tar.tgz";
// syslog(LOG_ERR, "get_archive2.php: $archive_file -> " . dirname($archive_file) );
if (! is_dir(dirname($archive_file)) ){
  if (!mkdir(dirname($archive_file), 0777, true)) {
    syslog(LOG_ERR, "get_archive.php: error creating archive folder for $archive_file");
  }
}
// We have two scratch spaces, one local at $doc_root/tmp.small, and one
// remote at $doc_root/tmp.large.

cleanup_location("$doc_root/tmp.small");

if (try_with_location("$archive_file", "$doc_root/tmp.small")) {
  exit(0);
}

cleanup_location("$doc_root/tmp.large");

if (try_with_location("$archive_file", "$doc_root/tmp.large")) {
  exit(0);
}
if (try_with_location("$archive_file", "$doc_root/tmp.large")) {
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
