<?php
session_start();
echo "<h2>Session actuelle :</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h2>URL actuelle :</h2>";
echo $_SERVER['REQUEST_URI'];
?>
