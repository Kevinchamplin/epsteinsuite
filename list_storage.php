<?php
header('Content-Type: text/plain');
$other = '/var/www/vhosts/kevinchamplin.com/epsteinsuite.com/storage/manual_uploads/photos/IMAGES 8/002';
if (is_dir($other)) {
    echo "Found storage in other dir: $other\n";
    print_r(scandir($other));
} else {
    echo "Storage not found in other dir: $other\n";
}
?>