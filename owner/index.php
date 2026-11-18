<?php
// Old address of the Class Admin panel – kept so existing bookmarks keep working.
header('Location: ../class-admin/' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;
