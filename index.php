<?php
/**
 * Root index.php - Redirects to public folder
 * This file ensures the application works when deployed to hosting
 * where the document root is the repository root.
 */

header('Location: public/index.php');
exit;