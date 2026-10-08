<?php
declare(strict_types=1);

$pageTitle = 'Template Designer';
ob_start();
require __DIR__ . '/manage/designer/editor.php';
$content = ob_get_clean();
require __DIR__ . '/layout.php';
