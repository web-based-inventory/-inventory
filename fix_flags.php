<?php
include "c:/wamp64/www/-inventory/config/database.php";

$query = "UPDATE products SET price_update_required = 0 WHERE selling_price > purchase_price";
mysqli_query($conn, $query);

$affected = mysqli_affected_rows($conn);
echo "Fixed " . $affected . " products where selling_price > purchase_price but flag was 1.\n";

$query2 = "UPDATE products SET price_update_required = 1 WHERE purchase_price > 0 AND selling_price <= purchase_price";
mysqli_query($conn, $query2);
$affected2 = mysqli_affected_rows($conn);
echo "Fixed " . $affected2 . " products where selling_price <= purchase_price but flag was 0.\n";

echo "Done!";
