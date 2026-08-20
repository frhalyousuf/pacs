<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function currentReporter(): ?array {
    return (isset($_SESSION['reporter']) && is_array($_SESSION['reporter'])) ? $_SESSION['reporter'] : null;
}

function reporterLoggedIn(): bool {
    return currentReporter() !== null;
}

function requireReporter(): void {
    if (!reporterLoggedIn()) {
        header('Location: reporter_login.php');
        exit;
    }
}
?>